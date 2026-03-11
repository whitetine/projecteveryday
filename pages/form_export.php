<?php
session_start();
require "../includes/pdo.php";
date_default_timezone_set("Asia/Taipei");

// 檢查權限
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!isset($u_ID)) {
    die("請先登入");
}

// 獲取表單提交 ID
$fs_ID = isset($_GET['fs_ID']) ? (int)$_GET['fs_ID'] : 0;

if ($fs_ID <= 0) {
    die("缺少參數");
}

// 獲取表單提交記錄
try {
    // 檢查是否有 form_example 欄位
    $stmt = $conn->query("SHOW COLUMNS FROM formdata LIKE 'form_example'");
    $hasFormExample = $stmt->fetch() !== false;
    
    $formExampleField = $hasFormExample ? ', f.form_example' : '';
    
    $stmt = $conn->prepare("
        SELECT 
            fs.*, 
            f.form_name, 
            f.form_category,
            f.form_des
            {$formExampleField},
            u.u_name as submitter_name,
            t.team_project_name,
            t.team_ID,
            g.group_name
        FROM formsubdata fs
        INNER JOIN formdata f ON fs.form_ID = f.form_ID
        INNER JOIN userdata u ON fs.fs_u_ID = u.u_ID
        LEFT JOIN teamdata t ON fs.fs_team_ID = t.team_ID
        LEFT JOIN groupdata g ON t.group_ID = g.group_ID
        WHERE fs.fs_ID = ?
    ");
    $stmt->execute([$fs_ID]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$submission) {
        die("找不到該表單提交記錄");
    }
    
    // 檢查權限（提交者、同團隊成員或管理員可以查看）
    $isAdmin = false;
    if ($role_ID && in_array($role_ID, [1, 2])) {
        $isAdmin = true;
    }
    
    $isSubmitter = ($submission['fs_u_ID'] === $u_ID);
    $isTeamMember = false;
    
    // 如果是團隊表單，檢查是否為團隊成員
    if (!$isAdmin && !$isSubmitter && $submission['fs_team_ID']) {
        $teamUserField = 'team_u_ID';
        $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM teammember 
            WHERE team_ID = ? AND {$teamUserField} = ? AND tm_status = 1
        ");
        $stmt->execute([$submission['fs_team_ID'], $u_ID]);
        $isTeamMember = $stmt->fetchColumn() > 0;
    }
    
    if (!$isAdmin && !$isSubmitter && !$isTeamMember) {
        die("無權限查看此表單");
    }
    
    // 獲取表單題目和答案
    $stmt = $conn->prepare("
        SELECT 
            fq.fq_ID,
            fq.fq_order,
            fq.fq_title,
            fq.fq_type,
            fq.fq_required,
            fa.fa_value,
            fa.fa_ID
        FROM formquestiondata fq
        LEFT JOIN formanswerdata fa ON fq.fq_ID = fa.fq_ID AND fa.fs_ID = ?
        WHERE fq.form_ID = ?
        ORDER BY fq.fq_order ASC
    ");
    $stmt->execute([$fs_ID, $submission['form_ID']]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 獲取團隊成員（如果有團隊）
    $teamMembers = [];
    if ($submission['fs_team_ID']) {
        $teamUserField = 'team_u_ID';
        $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $teamUserField = 'u_ID';
        }
        
        $stmt = $conn->prepare("
            SELECT 
                tm.{$teamUserField} as u_ID,
                u.u_name,
                e.class_ID,
                c.c_name as class_name
            FROM teammember tm
            INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
            LEFT JOIN enrollmentdata e ON u.u_ID = e.enroll_u_ID AND e.cohort_ID = (SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1)
            LEFT JOIN classdata c ON e.class_ID = c.c_ID
            WHERE tm.team_ID = ? 
              AND tm.tm_status = 1
              AND EXISTS (
                  SELECT 1 FROM userrolesdata ur 
                  WHERE ur.ur_u_ID = tm.{$teamUserField} 
                    AND ur.role_ID = 6 
                    AND ur.user_role_status = 1
              )
            ORDER BY tm.{$teamUserField}
        ");
        $stmt->execute([$submission['fs_team_ID']]);
        $teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Throwable $e) {
    die("載入資料失敗：" . $e->getMessage());
}

// 解析答案並自動填入使用者相關資料
foreach ($questions as &$q) {
    if (!empty($q['fa_value'])) {
        // 如果是 JSON 格式（複選框），解析它
        $decoded = json_decode($q['fa_value'], true);
        if (is_array($decoded)) {
            $q['fa_value'] = $decoded;
        }
    } else {
        $q['fa_value'] = '';
    }
    
    // 自動填入使用者相關資料
    $title = strtolower($q['fq_title']);
    $value = $q['fa_value'];
    
    // 如果答案為空，嘗試自動填入
    if (empty($value) || (is_array($value) && empty($value))) {
        // 檢查是否是班級相關欄位
        if (strpos($title, '班級') !== false || strpos($title, 'class') !== false) {
            // 獲取提交者的班級
            if (!empty($submission['fs_u_ID'])) {
                $stmt = $conn->prepare("
                    SELECT c.c_name 
                    FROM enrollmentdata e
                    INNER JOIN classdata c ON e.class_ID = c.c_ID
                    WHERE e.enroll_u_ID = ? 
                      AND e.cohort_ID = (SELECT cohort_ID FROM cohortdata WHERE cohort_status = 1 ORDER BY cohort_ID DESC LIMIT 1)
                      AND e.enroll_status = 1
                    LIMIT 1
                ");
                $stmt->execute([$submission['fs_u_ID']]);
                $className = $stmt->fetchColumn();
                if ($className) {
                    $q['fa_value'] = $className;
                }
            }
        }
        // 檢查是否是團隊名稱相關欄位
        elseif (strpos($title, '團隊名稱') !== false || strpos($title, '團隊') !== false || 
                strpos($title, '專題名稱') !== false || strpos($title, '專題標題') !== false ||
                strpos($title, 'team') !== false) {
            if (!empty($submission['team_project_name'])) {
                $q['fa_value'] = $submission['team_project_name'];
            }
        }
        // 檢查是否是類組相關欄位
        elseif (strpos($title, '類組') !== false || strpos($title, '組別') !== false || 
                strpos($title, 'group') !== false) {
            if (!empty($submission['group_name'])) {
                $q['fa_value'] = $submission['group_name'];
            }
        }
        // 檢查是否是提交人相關欄位
        elseif (strpos($title, '提交人') !== false || strpos($title, '申請人') !== false ||
                strpos($title, '姓名') !== false && strpos($title, '組員') === false) {
            if (!empty($submission['submitter_name'])) {
                $q['fa_value'] = $submission['submitter_name'];
            }
        }
        // 檢查是否是學號相關欄位
        elseif (strpos($title, '學號') !== false || strpos($title, 'student') !== false) {
            if (!empty($submission['fs_u_ID'])) {
                $q['fa_value'] = $submission['fs_u_ID'];
            }
        }
    }
}
unset($q);

// 引入模板系統（需要在這裡引入，因為模板需要 $conn）
require __DIR__ . '/form_export_templates.php';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($submission['form_name']) ?></title>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
        }
        
        /* 確保匯出時按鈕被隱藏 */
        .export-buttons {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        /* 匯出時隱藏按鈕 */
        body.exporting .export-buttons,
        body.exporting .no-print {
            display: none !important;
        }
        
        body {
            font-family: "DFKai-SB", "BiauKai", "標楷體", "Microsoft JhengHei", serif;
            font-size: 14pt;
            line-height: 1.6;
            color: #000;
            background: white;
            padding: 0;
            margin: 0;
            max-width: 210mm;
            margin: 0 auto;
        }
        
        .form-container {
            max-width: 210mm;
            margin: 0 auto;
            padding: 15mm 20mm;
            box-sizing: border-box;
        }
        
        @media print {
            .form-container {
                padding: 10mm 15mm;
            }
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 20px;
            margin-top: 0;
            padding-top: 10px;
        }
        
        .form-title {
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .form-category {
            font-size: 14pt;
            margin-bottom: 20px;
        }
        
        .form-section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .section-label {
            font-weight: bold;
            font-size: 14pt;
            margin-bottom: 10px;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
        }
        
        .form-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            border: 1px solid #000;
        }
        
        .form-table th,
        .form-table td {
            border: 1px solid #000;
            padding: 8px 12px;
            text-align: left;
            vertical-align: top;
        }
        
        .form-table th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }
        
        .form-field {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
        }
        
        .form-field-two-col {
            display: flex;
            margin-bottom: 20px;
            align-items: flex-start;
        }
        
        .field-label-col {
            font-weight: bold;
            width: 150px;
            flex-shrink: 0;
            padding-right: 15px;
            text-align: left;
        }
        
        .field-value-col {
            flex: 1;
            min-height: 30px;
        }
        
        .field-line {
            border-bottom: 1px solid #000;
            min-height: 30px;
            width: 100%;
        }
        
        .field-value-text {
            border-bottom: 1px solid #000;
            min-height: 30px;
            padding: 5px 0;
            word-wrap: break-word;
        }
        
        .field-value-box {
            border: 1px solid #000;
            min-height: 100px;
            padding: 10px;
            word-wrap: break-word;
            background: white;
        }
        
        .field-label {
            font-weight: bold;
            display: inline-block;
            width: 120px;
            flex-shrink: 0;
            padding-right: 10px;
        }
        
        .field-value {
            min-height: 30px;
            border-bottom: 1px solid #000;
            padding: 5px 0;
            flex: 1;
            display: inline-block;
        }
        
        .field-value-empty {
            min-height: 30px;
            border-bottom: 1px dotted #999;
            padding: 5px 0;
            color: #999;
        }
        
        .question-item {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .question-title {
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .question-answer {
            min-height: 40px;
            border: 1px solid #000;
            padding: 10px;
            background: white;
        }
        
        .question-answer-empty {
            min-height: 40px;
            border: 1px dotted #999;
            padding: 10px;
            color: #999;
        }
        
        .signature-section {
            margin-top: 40px;
            text-align: right;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            margin: 40px 0 10px auto;
            text-align: center;
            padding-top: 5px;
        }
        
        .export-buttons {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .btn-group {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-export {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .btn-export:hover {
            background: #0056b3;
        }
        
        .btn-print {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .btn-print:hover {
            background: #218838;
        }
    </style>
</head>
<body>
    <div class="export-buttons no-print">
        <div class="btn-group" role="group">
            <button class="btn-export" onclick="exportToPDF()" title="匯出為 PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <button class="btn-export" onclick="exportToWord()" title="匯出為 Word">
                <i class="fas fa-file-word"></i> Word
            </button>
            <button class="btn-export" onclick="exportToExcel()" title="匯出為 Excel">
                <i class="fas fa-file-excel"></i> Excel
            </button>
            <button class="btn-print" onclick="window.print()" title="列印">
                <i class="fas fa-print"></i> 列印
            </button>
        </div>
    </div>

    <?php
    // 根據表單類別選擇不同的模板
    renderFormByCategory($submission, $questions, $teamMembers);
    ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <script>
        const formName = '<?= htmlspecialchars($submission['form_name'], ENT_QUOTES) ?>';
        const dateStr = new Date().toISOString().slice(0, 10).replace(/-/g, '');
        
        function exportToPDF() {
            // 隱藏按鈕
            document.body.classList.add('exporting');
            const buttons = document.querySelector('.export-buttons');
            if (buttons) buttons.style.display = 'none';
            
            // 滾動到頂部，確保從最上方開始截圖
            window.scrollTo(0, 0);
            
            const element = document.body;
            const fileName = formName + '_' + dateStr + '.pdf';
            
            const opt = {
                margin: [5, 10, 10, 10],  // 減少上邊距
                filename: fileName,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { 
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    ignoreElements: function(element) {
                        // 忽略匯出按鈕
                        return element.classList && element.classList.contains('export-buttons');
                    },
                    y: 0,
                    scrollY: 0,
                    windowWidth: document.documentElement.scrollWidth,
                    windowHeight: document.documentElement.scrollHeight
                },
                jsPDF: { 
                    unit: 'mm', 
                    format: 'a4', 
                    orientation: 'portrait',
                    compress: true
                },
                pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
            };
            
            // 等待頁面完全載入後再匯出
            setTimeout(function() {
                html2pdf().set(opt).from(element).save().then(function() {
                    console.log('PDF 匯出成功');
                    document.body.classList.remove('exporting');
                    if (buttons) buttons.style.display = '';
                }).catch(function(error) {
                    console.error('PDF 匯出失敗:', error);
                    alert('PDF 匯出失敗，請使用瀏覽器的列印功能（Ctrl+P）');
                    document.body.classList.remove('exporting');
                    if (buttons) buttons.style.display = '';
                });
            }, 100);
        }
        
        function exportToWord() {
            // 獲取 HTML 內容，排除按鈕
            const bodyClone = document.body.cloneNode(true);
            const buttons = bodyClone.querySelector('.export-buttons');
            if (buttons) buttons.remove();
            
            const content = bodyClone.innerHTML;
            
            // 創建 Word 文檔的 HTML 結構
            const htmlContent = `
                <!DOCTYPE html>
                <html xmlns:o='urn:schemas-microsoft-com:office:office' 
                      xmlns:w='urn:schemas-microsoft-com:office:word' 
                      xmlns='http://www.w3.org/TR/REC-html40'>
                <head>
                    <meta charset='utf-8'>
                    <title>${formName}</title>
                    <!--[if gte mso 9]>
                    <xml>
                        <w:WordDocument>
                            <w:View>Print</w:View>
                            <w:Zoom>90</w:Zoom>
                            <w:DoNotOptimizeForBrowser/>
                        </w:WordDocument>
                    </xml>
                    <![endif]-->
                    <style>
                        @page {
                            size: A4;
                            margin: 2cm;
                        }
                        body {
                            font-family: "DFKai-SB", "BiauKai", "標楷體", "Microsoft JhengHei", serif;
                            font-size: 12pt;
                            line-height: 1.6;
                        }
                        .export-buttons { display: none !important; }
                        ${document.querySelector('style').innerHTML.replace(/\.export-buttons[^}]*{[^}]*}/g, '')}
                    </style>
                </head>
                <body>
                    ${content}
                </body>
                </html>
            `;
            
            // 創建 Blob
            const blob = new Blob(['\ufeff', htmlContent], {
                type: 'application/msword'
            });
            
            // 下載
            const fileName = formName + '_' + dateStr + '.doc';
            saveAs(blob, fileName);
        }
        
        function exportToExcel() {
            // 收集表單資料
            const data = [];
            
            // 表單標題
            data.push(['表單名稱', formName]);
            data.push(['提交時間', '<?= htmlspecialchars($submission['fs_submitted_d'] ?? date('Y-m-d H:i:s')) ?>']);
            data.push(['提交人', '<?= htmlspecialchars($submission['submitter_name'] ?? '') ?>']);
            if ('<?= htmlspecialchars($submission['team_project_name'] ?? '') ?>') {
                data.push(['團隊', '<?= htmlspecialchars($submission['team_project_name']) ?>']);
            }
            data.push([]); // 空行
            
            // 題目和答案
            data.push(['題目', '答案']);
            <?php foreach ($questions as $q): ?>
            <?php
            $title = htmlspecialchars($q['fq_title'], ENT_QUOTES);
            $value = '';
            if (!empty($q['fa_value'])) {
                if (is_array($q['fa_value'])) {
                    $value = implode('、', array_map(function($v) {
                        return htmlspecialchars($v, ENT_QUOTES);
                    }, $q['fa_value']));
                } else {
                    $value = htmlspecialchars($q['fa_value'], ENT_QUOTES);
                }
            } else {
                $value = '（未填寫）';
            }
            ?>
            data.push(['<?= $title ?>', '<?= $value ?>']);
            <?php endforeach; ?>
            
            // 轉換為 CSV 格式
            const csvContent = data.map(row => {
                return row.map(cell => {
                    // 處理包含逗號、引號或換行的內容
                    if (typeof cell === 'string' && (cell.includes(',') || cell.includes('"') || cell.includes('\n'))) {
                        return '"' + cell.replace(/"/g, '""') + '"';
                    }
                    return cell || '';
                }).join(',');
            }).join('\n');
            
            // 添加 BOM 以支援中文
            const BOM = '\uFEFF';
            const blob = new Blob([BOM + csvContent], {
                type: 'text/csv;charset=utf-8;'
            });
            
            // 下載
            const fileName = formName + '_' + dateStr + '.csv';
            saveAs(blob, fileName);
        }
    </script>
</body>
</html>

