<?php
/**
 * 匯出資料表欄位結構
 * 原本用於匯出 document_form_supplements，現可用於 document_submissions 等任意資料表。
 */

session_start();
require __DIR__ . '/../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

// 允許的匯出格式
$format = $_GET['format'] ?? 'html'; // html, json, sql, csv
// 預設改為 document_submissions，仍可透過 ?table=xxx 指定其他資料表
$table_name = $_GET['table'] ?? 'document_submissions';

// 驗證表名（防止 SQL 注入）
if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
    die('無效的資料表名稱');
}

try {
    // 查詢表結構
    $stmt = $conn->query("DESCRIBE `$table_name`");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        die("找不到資料表：$table_name");
    }
    
    // 查詢表的 CREATE TABLE 語句
    $stmt_create = $conn->query("SHOW CREATE TABLE `$table_name`");
    $create_table = $stmt_create->fetch(PDO::FETCH_ASSOC);
    $create_sql = $create_table['Create Table'] ?? '';
    
    // 根據格式輸出
    switch ($format) {
        case 'json':
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $table_name . '_structure.json"');
            echo json_encode([
                'table_name' => $table_name,
                'columns' => $columns,
                'create_table' => $create_sql
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'sql':
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $table_name . '_structure.sql"');
            echo "-- 資料表結構：$table_name\n";
            echo "-- 匯出時間：" . date('Y-m-d H:i:s') . "\n\n";
            echo $create_sql . ";\n\n";
            echo "-- 欄位說明：\n";
            foreach ($columns as $col) {
                echo "-- {$col['Field']}: {$col['Type']}";
                if ($col['Null'] === 'NO') echo " NOT NULL";
                if ($col['Default'] !== null) echo " DEFAULT '{$col['Default']}'";
                if ($col['Key'] !== '') echo " [{$col['Key']}]";
                echo "\n";
            }
            exit;
            
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $table_name . '_structure.csv"');
            
            // 輸出 BOM 以支援 Excel 正確顯示中文
            echo "\xEF\xBB\xBF";
            
            // CSV 標題
            echo "欄位名稱,資料類型,允許NULL,預設值,鍵,額外資訊\n";
            
            // CSV 資料
            foreach ($columns as $col) {
                $field = $col['Field'];
                $type = $col['Type'];
                $null = $col['Null'];
                $default = $col['Default'] ?? '';
                $key = $col['Key'];
                $extra = $col['Extra'] ?? '';
                
                // CSV 格式：用雙引號包圍，內部雙引號用兩個雙引號轉義
                echo '"' . str_replace('"', '""', $field) . '",';
                echo '"' . str_replace('"', '""', $type) . '",';
                echo '"' . str_replace('"', '""', $null) . '",';
                echo '"' . str_replace('"', '""', $default) . '",';
                echo '"' . str_replace('"', '""', $key) . '",';
                echo '"' . str_replace('"', '""', $extra) . '"';
                echo "\n";
            }
            exit;
            
        case 'html':
        default:
            // HTML 格式（網頁顯示）
            ?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資料表結構：<?= htmlspecialchars($table_name) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "Microsoft JhengHei", "微軟正黑體", Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        .info-bar {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .info-bar .table-name {
            font-size: 18px;
            font-weight: bold;
            color: #2e7d32;
        }
        .info-bar .export-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .info-bar .export-links a {
            padding: 8px 16px;
            background: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background 0.3s;
        }
        .info-bar .export-links a:hover {
            background: #45a049;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: #fff;
        }
        th {
            background: #4CAF50;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .type-col {
            font-family: 'Courier New', monospace;
            color: #d32f2f;
            font-weight: bold;
        }
        .key-col {
            font-weight: bold;
            color: #1976d2;
        }
        .null-col {
            color: #666;
        }
        .create-sql {
            margin-top: 30px;
            padding: 20px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .create-sql h2 {
            margin-top: 0;
            color: #333;
        }
        .create-sql pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 13px;
            line-height: 1.5;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-box {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            flex: 1;
            min-width: 150px;
        }
        .stat-box .label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        .stat-box .value {
            font-size: 24px;
            font-weight: bold;
            color: #1976d2;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 資料表結構匯出</h1>
        
        <div class="info-bar">
            <div class="table-name">資料表：<?= htmlspecialchars($table_name) ?></div>
            <div class="export-links">
                <a href="?table=<?= urlencode($table_name) ?>&format=json">📥 匯出 JSON</a>
                <a href="?table=<?= urlencode($table_name) ?>&format=sql">📥 匯出 SQL</a>
                <a href="?table=<?= urlencode($table_name) ?>&format=csv">📥 匯出 CSV</a>
            </div>
        </div>
        
        <?php
        // 統計資訊
        $total_columns = count($columns);
        $primary_keys = array_filter($columns, function($col) { return $col['Key'] === 'PRI'; });
        $not_null = array_filter($columns, function($col) { return $col['Null'] === 'NO'; });
        ?>
        
        <div class="stats">
            <div class="stat-box">
                <div class="label">總欄位數</div>
                <div class="value"><?= $total_columns ?></div>
            </div>
            <div class="stat-box">
                <div class="label">主鍵欄位</div>
                <div class="value"><?= count($primary_keys) ?></div>
            </div>
            <div class="stat-box">
                <div class="label">必填欄位</div>
                <div class="value"><?= count($not_null) ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>欄位名稱</th>
                    <th>資料類型</th>
                    <th>允許 NULL</th>
                    <th>預設值</th>
                    <th>鍵</th>
                    <th>額外資訊</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns as $col): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($col['Field']) ?></strong></td>
                    <td class="type-col"><?= htmlspecialchars($col['Type']) ?></td>
                    <td class="null-col"><?= $col['Null'] === 'YES' ? '✓ 是' : '✗ 否' ?></td>
                    <td><?= $col['Default'] !== null ? htmlspecialchars($col['Default']) : '<em>NULL</em>' ?></td>
                    <td class="key-col">
                        <?php
                        if ($col['Key'] === 'PRI') echo '🔑 PRIMARY';
                        elseif ($col['Key'] === 'UNI') echo '🔒 UNIQUE';
                        elseif ($col['Key'] === 'MUL') echo '🔗 INDEX';
                        else echo '—';
                        ?>
                    </td>
                    <td><?= htmlspecialchars($col['Extra'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="create-sql">
            <h2>📝 CREATE TABLE 語句</h2>
            <pre><?= htmlspecialchars($create_sql) ?></pre>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
            <strong>💡 提示：</strong>
            <ul style="margin: 10px 0 0 20px; padding: 0;">
                <li>點擊上方的匯出連結可下載不同格式的檔案</li>
                <li>JSON 格式適合程式處理</li>
                <li>SQL 格式可直接用於建立資料表</li>
                <li>CSV 格式適合用 Excel 開啟</li>
            </ul>
        </div>
    </div>
</body>
</html>
            <?php
            break;
    }
    
} catch (PDOException $e) {
    die("錯誤：" . $e->getMessage());
}
?>
