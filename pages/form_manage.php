<?php
session_start();
date_default_timezone_set('Asia/Taipei');
require '../includes/pdo.php';

// 檢查權限
$role_ID = $_SESSION['role_ID'] ?? null;
if (!in_array($role_ID, [1, 2])) {
    echo '<div class="alert alert-danger">您沒有權限訪問此頁面</div>';
    exit;
}

// 目標設定選項：頁面輸出時就從資料庫帶出，切頁再回來時選單不會是空的（不依賴 JS 動態載入）
$targetGrades = [];
$targetGroups = [];
$targetClasses = [];
try {
    $sql = "SELECT DISTINCT c.year_label AS enroll_grade, c.cohort_name, c.cohort_ID
            FROM cohortdata c
            WHERE c.cohort_status = 1 AND (c.cohort_end_d IS NULL OR c.cohort_end_d >= CURDATE())
            ORDER BY c.year_label ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $targetGrades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */
}
try {
    // 類組：僅顯示屬於「有效 cohort（cohort_status=1 且未結束）」的群組
    // 透過 teamdata 連結 cohortdata，確保只列出目前學年度在用的類組
    $sql = "SELECT DISTINCT g.group_ID, g.group_name
            FROM groupdata g
            INNER JOIN teamdata t ON t.group_ID = g.group_ID
            INNER JOIN cohortdata c ON c.cohort_ID = t.cohort_ID
            WHERE g.group_status = 1
              AND c.cohort_status = 1
              AND (c.cohort_end_d IS NULL OR c.cohort_end_d >= CURDATE())
            ORDER BY g.group_ID ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $targetGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */
}
try {
    $sql = "SELECT DISTINCT c.c_ID, c.c_name
            FROM classdata c
            INNER JOIN enrollmentdata e ON c.c_ID = e.class_ID
            INNER JOIN cohortdata co ON co.cohort_ID = e.cohort_ID
            WHERE e.enroll_status = 1 AND co.cohort_status = 1
              AND (co.cohort_end_d IS NULL OR co.cohort_end_d >= CURDATE())
            ORDER BY c.c_ID ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $targetClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */
}
?>

<link rel="stylesheet" href="css/form_manage.css">

<div class="form-manage-container">
    <!-- 頁面標題 -->
    <div class="page-header-section">
        <h1 class="page-title">
            <i class="fa-solid fa-file-alt"></i>
            申請與繳交管理
        </h1>
        <p class="page-subtitle">管理各類文件表單，設定欄位、題型與開放時間</p>
    </div>

    <!-- 操作按鈕 -->
    <div class="page-actions">
        <div></div>
        <button class="btn-add-form" id="btnAddForm">
            <i class="fa-solid fa-plus"></i>
            新增表單
        </button>
    </div>

    <!-- 表單列表 -->
    <div class="forms-list-card">
        <table class="forms-table">
            <thead>
                <tr>
                    <th>文件名稱</th>
                    <th>文件分類</th>
                    <th class="col-target">目標設定對象</th>
                    <th class="col-datetime">開放時間 / 截止時間</th>
                    <th>狀態</th>
                    <th class="col-actions">操作</th>
                </tr>
            </thead>
            <tbody id="formsTableBody">
                <!-- 初始狀態為空，由 JS 立即填充 -->
            </tbody>
        </table>
    </div>
</div>

<!-- 新增/編輯表單 Modal -->
<div class="modal" id="formModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">新增表單</h2>
            <button type="button" class="modal-close" id="btnCloseModal">&times;</button>
        </div>
        <div class="modal-body">
            <form id="formForm">
                <input type="hidden" id="document_id" name="document_id" value="0">

                <!-- 系統說明提示（固定顯示於頁面上方，僅說明預設行為，不影響欄位設定） -->
                <div class="form-section system-notice-box" role="note" aria-label="系統說明">
                    <p class="system-notice-text">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        所有學生填寫本表單時，系統將自動顯示該組的專題欄位、專題生與指導老師（唯讀），無需於表單中另行新增相關欄位。
                    </p>
                </div>

                <!-- 基本設定區 -->
                <div class="form-section">
                    <div class="form-section-title">基本設定</div>

                    <div class="form-group">
                        <label for="document_name" class="required">文件名稱</label>
                        <input type="text" class="form-control" id="document_name" name="document_name" required>
                    </div>

                    <input type="hidden" id="is_required" name="is_required" value="0">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="open_datetime">開放時間</label>
                            <input type="datetime-local" class="form-control" id="open_datetime" name="open_datetime">
                        </div>

                        <div class="form-group">
                            <label for="close_datetime">截止時間</label>
                            <input type="datetime-local" class="form-control" id="close_datetime" name="close_datetime">
                        </div>
                    </div>
                </div>

                <!-- 目標設定區（學級、類組皆為必填，至少各選一項） -->
                <div class="form-section">
                    <div class="form-section-title">目標設定</div>
                    <p style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;">
                        設定此表單的開放對象。<strong>學級(屆別)、類組皆為必填</strong>，請至少各選一項；可多選，按住 Ctrl/Cmd 鍵。
                    </p>

                    <div id="targetSettingsContainer">
                        <div class="form-row" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="doc_target_grades" class="required">學級 (屆別)</label>
                                <select class="form-control" id="doc_target_grades" name="doc_target_grades" multiple
                                    size="6" style="min-height: 120px;" required aria-required="true">
                                    <?php foreach ($targetGrades as $g): ?>
                                        <option
                                            value="<?= htmlspecialchars($g['enroll_grade'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                                            <?= htmlspecialchars($g['cohort_name'] ?? ($g['enroll_grade'] ?? '') . '級', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="doc_target_groups" class="required">類組</label>
                                <select class="form-control" id="doc_target_groups" name="doc_target_groups" multiple
                                    size="6" style="min-height: 120px;" required aria-required="true">

                                    <?php foreach ($targetGroups as $g): ?>
                                        <option value="<?= htmlspecialchars($g['group_ID'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($g['group_name'] ?? $g['group_ID'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 標題設定區 -->
                <div class="form-section questions-section">
                    <div class="form-section-title">標題設定</div>
                    <div id="questionsContainer">
                        <!-- 欄位將動態插入這裡 -->
                    </div>
                    <div class="question-actions-bottom"
                        style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem;">
                        <button type="button" class="btn-add-question" id="btnAddQuestion">
                            <i class="fa-solid fa-plus me-1"></i>新增欄位
                        </button>
                    </div>
                </div>
                <!-- 補充附件設定（不當欄位） -->
                <div class="form-section" style="margin-top: 1rem;">
                    <div
                        style="padding: 0.75rem 1rem; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0;">
                        <label
                            style="font-weight: 500; display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.35rem;">
                            <input type="checkbox" id="supplement_enabled" checked style="margin: 0;">
                            <span>啟用補充附件區塊（學生端底部「補充附件（PDF）」）</span>
                        </label>
                        <textarea id="supplement_note" rows="2" class="form-control"
                            style="margin-top: 0.35rem; font-size: 0.9rem;"
                            placeholder="例如：可上傳一份 PDF 作為表單補充說明。"></textarea>
                        <small class="text-muted">此說明文字會顯示在學生端補充附件區塊下方，說明該補充檔案應包含的內容。</small>
                    </div>
                </div>
                <div class="form-section" style="margin-top: 1rem;">
                    <div
                        style="padding: 0.75rem 1rem; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0;">
                        <label
                            style="font-weight: 500; display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.35rem;">
                            <input type="checkbox" id="form_exresultdata" name="exresultdata" style="margin: 0;">
                            <span>學生繳交該表單時，必須完成專題預期成果表。</span>
                        </label>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" id="btnCancelForm">取消</button>
            <button type="button" class="btn-save" id="btnSaveForm">儲存</button>
        </div>
    </div>
</div>

<!-- 從資料庫帶入 Modal -->
<div class="modal db-lookup-modal" id="dbLookupModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="dbLookupTitle">從資料庫帶入</h2>
            <button class="modal-close" id="btnCloseDbLookup">&times;</button>
        </div>
        <div class="modal-body">
            <input type="text" class="db-lookup-search" id="dbLookupSearch" placeholder="輸入學號或姓名搜尋..."
                autocomplete="off">
            <div class="db-lookup-list" id="dbLookupList"></div>
        </div>
    </div>
</div>

<script src="js/form_manage.js?v=20260310"></script>