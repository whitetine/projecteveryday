
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>表單欄位管理</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* 簡單的後台樣式 */
        body { font-family: sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { margin-bottom: 20px; color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        
        /* 表格樣式 */
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: bold; color: #555; }
        tr:hover { background-color: #f1f1f1; }
        
        /* 按鈕樣式 */
        .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        .btn-primary { background: #4e73df; color: white; }
        .btn-success { background: #1cc88a; color: white; }
        .btn-danger { background: #e74a3b; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        
        /* 狀態標籤 */
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; color: white; }
        .badge-active { background: #1cc88a; }
        .badge-inactive { background: #858796; }
        .badge-req { background: #e74a3b; }

        /* Modal 樣式 (簡單版) */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 20px; width: 500px; border-radius: 8px; position: relative; }
        .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .form-row { display: flex; gap: 10px; }
        .hint { font-size: 12px; color: #888; margin-top: 4px; display: block; }
    </style>
</head>
<body>

<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h1><i class="fas fa-cogs"></i> 申請表單欄位設定</h1>
        <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> 新增欄位</button>
    </div>

    <table>
        <thead>
            <tr>
                <th width="50">排序</th>
                <th>顯示名稱 (Label)</th>
                <th>變數名稱 (Key)</th>
                <th>類型</th>
                <th>資料來源</th>
                <th>設定</th>
                <th>狀態</th>
                <th width="150">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fields as $f): ?>
            <tr>
                <td><?= $f['sort_order'] ?></td>
                <td>
                    <?= htmlspecialchars($f['field_label']) ?>
                    <?php if($f['is_required']) echo '<span class="badge badge-req">必填</span>'; ?>
                </td>
                <td><code><?= htmlspecialchars($f['field_key']) ?></code></td>
                <td><?= $f['field_type'] ?></td>
                <td><?= $f['data_source'] ? htmlspecialchars($f['data_source']) : '-' ?></td>
                <td>
                    <small style="color:#666">
                        <?= $f['placeholder'] ? '提示: '.$f['placeholder'] : '' ?>
                    </small>
                </td>
                <td>
                    <?php if($f['is_active']): ?>
                        <span class="badge badge-active">顯示中</span>
                    <?php else: ?>
                        <span class="badge badge-inactive">已隱藏</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-success btn-sm" 
                            onclick='openModal(<?= json_encode($f) ?>)'>
                        <i class="fas fa-edit"></i>
                    </button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除嗎？這可能會影響舊資料顯示！');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $f['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="fieldModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">新增欄位</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="field_id">
            
            <div class="form-group">
                <label>顯示名稱 (Label)</label>
                <input type="text" name="field_label" id="field_label" required placeholder="例如：專題名稱">
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label>變數名稱 (Key)</label>
                    <input type="text" name="field_key" id="field_key" required placeholder="例如：project_name">
                    <span class="hint">對應資料庫或 JS ID，請用英文</span>
                </div>
                <div class="form-group" style="flex:1">
                    <label>排序 (Sort)</label>
                    <input type="number" name="sort_order" id="sort_order" value="10">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label>輸入類型</label>
                    <select name="field_type" id="field_type" onchange="toggleDataSource()">
                        <option value="text">單行文字 (text)</option>
                        <option value="textarea">多行文字 (textarea)</option>
                        <option value="select">下拉選單 (select)</option>
                        <option value="file">檔案上傳 (file)</option>
                        <option value="member_picker">成員選擇器 (特殊)</option>
                        <option value="date">日期 (date)</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1">
                    <label>資料來源 (下拉選單用)</label>
                    <select name="data_source" id="data_source" disabled>
                        <option value="">無 (非下拉選單)</option>
                        <option value="groupdata">類組資料庫 (groupdata)</option>
                        <option value="teacherdata">教師資料庫 (teacherdata)</option>
                        </select>
                </div>
            </div>

            <div class="form-group">
                <label>輸入提示 (Placeholder)</label>
                <input type="text" name="placeholder" id="placeholder" placeholder="例如：請輸入...">
            </div>

            <div class="form-group">
                <label style="display:inline-block; margin-right: 15px;">
                    <input type="checkbox" name="is_required" id="is_required" value="1" checked> 
                    設為必填
                </label>
                <label style="display:inline-block;">
                    <input type="checkbox" name="is_active" id="is_active" value="1" checked> 
                    啟用顯示
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%">儲存設定</button>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById("fieldModal");
    const modalTitle = document.getElementById("modalTitle");

    function openModal(data = null) {
        modal.style.display = "block";
        if (data) {
            // 編輯模式：填入資料
            modalTitle.innerText = "編輯欄位";
            document.getElementById('field_id').value = data.id;
            document.getElementById('field_label').value = data.field_label;
            document.getElementById('field_key').value = data.field_key;
            document.getElementById('field_type').value = data.field_type;
            document.getElementById('data_source').value = data.data_source || '';
            document.getElementById('placeholder').value = data.placeholder;
            document.getElementById('sort_order').value = data.sort_order;
            document.getElementById('is_required').checked = (data.is_required == 1);
            document.getElementById('is_active').checked = (data.is_active == 1);
        } else {
            // 新增模式：清空
            modalTitle.innerText = "新增欄位";
            document.getElementById('field_id').value = '';
            document.getElementById('field_label').value = '';
            document.getElementById('field_key').value = '';
            document.getElementById('field_type').value = 'text';
            document.getElementById('data_source').value = '';
            document.getElementById('placeholder').value = '';
            document.getElementById('sort_order').value = '10';
            document.getElementById('is_required').checked = true;
            document.getElementById('is_active').checked = true;
        }
        toggleDataSource();
    }

    function closeModal() {
        modal.style.display = "none";
    }

    // 當選擇下拉選單類型時，才允許選擇資料來源
    function toggleDataSource() {
        const type = document.getElementById('field_type').value;
        const sourceSelect = document.getElementById('data_source');
        
        if (type === 'select') {
            sourceSelect.disabled = false;
        } else {
            sourceSelect.disabled = true;
            sourceSelect.value = "";
        }
    }

    // 點擊 Modal 外部關閉
    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }
</script>

</body>
</html>