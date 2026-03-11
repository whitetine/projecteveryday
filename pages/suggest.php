
<?php
session_start();
$role_ID = $_SESSION["role_ID"] ?? null;

// 召集人(7) 與科辦(2) 都視為可新增/加入組別的角色
$isSuggestConvener = in_array((int)$role_ID, [2, 7], true);
// 召集人(7) 預設不顯示編輯/存檔，僅在開啟「未送交科辦」的建議表時由 JS 顯示
$isConvenerRole = ((int)$role_ID === 7);
?>
<!-- === 建議系統 === -->
<div class="suggest-wrapper" data-suggest-page="true" data-role-id="<?= (int)$role_ID ?>">

    <div class="suggest-header">
        <h4 class="suggest-title">專題報告建議</h4>
        <p class="suggest-subtitle">請選擇屆別和類組後填寫各組別的審查建議</p>
    </div>

    <!-- 篩選選單 -->
    <div class="suggest-filter-box">
        <div class="suggest-filter-box-row1">
            <div class="suggest-cohort-box">
                <label>屆別</label>
                <select id="sg-cohort" class="form-select"></select>
            </div>
            <div class="suggest-group-box">
                <label>選擇類組</label>
                <select id="sg-group" class="form-select" disabled>
                    <option value="">請先選擇屆別</option>
                </select>
            </div>
            <div class="suggest-title-box">
                <label>標題</label>
                <input type="text" id="sg-title" class="form-control" placeholder="請輸入標題" disabled>
            </div>
        </div>
        <div class="suggest-filter-box-row-buttons">
            <div class="suggest-export-box">
                <button id="sg-back-to-integrate-btn" class="sg-btn-back-home" data-href="main.php#pages/integrate.php">
                    <i class="fa-solid fa-home me-1"></i><span>返回上一頁</span>
                </button>
               
                <button id="sg-edit-all-btn" class="sg-btn-edit-all" disabled <?= $isConvenerRole ? 'style="display:none;"' : '' ?>>
                    <i class="fa-solid fa-edit me-1"></i><span>編輯</span>
                </button>
                <button id="sg-new-suggest-btn" class="sg-btn-new-suggest" disabled style="display: none;">
                    <i class="fa-solid fa-plus me-1"></i><span>新增建議表</span>
                </button>
                <button id="sg-add-team-btn" class="sg-btn-add-team" disabled style="display: none;">
                    <i class="fa-solid fa-user-plus me-1"></i><span>加入組別</span>
                </button>
                <button id="sg-save-btn" class="sg-btn-save-all" disabled <?= $isConvenerRole ? 'style="display:none;"' : '' ?>>
                    <i class="fa-solid fa-save me-1"></i><span>存檔</span>
                </button>
              
                
                <button id="sg-export-btn" class="sg-btn-export" disabled>
                    <i class="fa-solid fa-download me-1"></i><span>匯出PDF</span>
                </button>
                <button id="sg-export-word-btn" class="sg-btn-export-word" disabled>
                    <i class="fa-solid fa-file-word me-1"></i><span>匯出Word</span>
                </button>
               
            </div>
        </div>
    </div>

    <!-- 組別列表提示 -->
    <p class="sg-team-list-hint" id="sg-team-list-hint" style="display: none;">可點擊組別列展開／收合指導老師與學生建議；可以用滑鼠拖移表格列來調整順序</p>

    <!-- 檔案列表（當有資料時顯示） -->
    <div id="sg-file-list" class="sg-file-list-container" style="display: none;">
        <!-- 檔案列表將在這裡動態生成 -->
    </div>

    <!-- 組別列表 - 表格形式（編輯模式時顯示） -->
    <div id="sg-team-list" class="sg-team-list-container">
        <table class="sg-suggest-table" id="sg-suggest-table">
            <colgroup>
                <col class="sg-col-name" style="width:26%">
                <col class="sg-col-group" style="width:14%">
                <col class="sg-col-suggest" style="width:28%">
                <col class="sg-col-score" style="width:10%">
                <col class="sg-col-status" style="width:12%">
                <col class="sg-col-action" style="width:10%">
            </colgroup>
            <thead>
                <tr>
                    <th class="sg-th-name">組別名稱</th>
                    <th class="sg-th-group">類組</th>
                    <th class="sg-th-suggest">組別建議</th>
                    <th class="sg-th-score">評分</th>
                    <th class="sg-th-status">選擇審查結果</th>
                    <th class="sg-th-action">操作</th>
                </tr>
            </thead>
            <tbody id="sg-team-tbody"></tbody>
        </table>
    </div>

</div>

<!-- 引入 CSS -->
<link rel="stylesheet" href="../css/suggest.css">
<!-- 載入 suggest.js（使用立即執行函數，避免 return 語句錯誤） -->
<script>
window.isSuggestConvener = <?php echo json_encode($isSuggestConvener); ?>;
(function loadSuggestJS() {
    if (document.querySelector('script[src*="suggest.js"]')) {
        if (typeof window.initSuggest === 'function') {
            setTimeout(function() { window.initSuggest(); }, 100);
        }
        return;
    }
    var s = document.createElement('script');
    s.src = '../js/suggest.js?v=' + Date.now();
    s.onload = function() {
        setTimeout(function() {
            if (typeof window.initSuggest === 'function') {
                window.initSuggest();
            }
        }, 100);
    };
    document.head.appendChild(s);
})();
</script>
