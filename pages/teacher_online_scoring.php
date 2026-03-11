<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
    echo "<script>alert('請先登入');location.href='../index.php';</script>";
    exit;
}
$role_ID = $_SESSION['role_ID'] ?? null;
if ((int)$role_ID !== 4) {
    echo "<div class=\"alert alert-danger\">此頁面僅限指導老師使用</div>";
    exit;
}
$cohort_ID = (int)($_SESSION['cohort_ID'] ?? 0);
$cohort_name = isset($_SESSION['cohort_name']) ? htmlspecialchars($_SESSION['cohort_name'], ENT_QUOTES, 'UTF-8') : '';
?>
<link rel="stylesheet" href="../css/teacher_online_scoring.css?v=<?= time() ?>">
<script>
window.TEACHER_COHORT_ID = <?= $cohort_ID ?>;
window.TEACHER_COHORT_NAME = "<?= $cohort_name ?>";
</script>

<div class="teacher-scoring-page">
    <header class="page-header">
        <h2 class="page-title">
            <i class="fa-solid fa-clipboard-check me-2" style="color: #ffc107;"></i>審查建議&評分
        </h2>
    </header>
    <p class="page-subtitle"><?= $cohort_ID ? ('目前顯示：' . $cohort_name . '，') : '' ?>為各團隊填寫評分與建議</p>

    <div class="row g-4">
        <!-- 左側：評分時段（列表點選，選後自動載入團隊） -->
        <div class="col-md-4">
            <!-- 評分時段（該屆所有評分時段，列表點選，同 student_review） -->
            <div id="tosSuggestFormCard" class="card upload-card shadow-sm mb-4" style="display: none;">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fa-solid fa-calendar-days me-2"></i>評分時段</h5>
                </div>
                <div class="card-body p-0">
                    <div id="tosPeriodList" class="period-list">
                        <div class="text-center p-3 text-muted">
                            <i class="fas fa-spinner fa-spin"></i> 載入中...
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <!-- 右側：團隊列表 -->
        <div class="col-md-8">
            <div id="tosTeamSection" class="card upload-card shadow-sm" style="display: none;">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="mb-0"><i class="fa-solid fa-people-group me-2"></i>團隊列表</h5>
                    <button type="button" id="tosSubmitScoreBtn" class="btn btn-success btn-sm" style="display: none;">
                        <i class="fa-solid fa-paper-plane me-1"></i>送出
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 teacher-scoring-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>團隊名稱</th>
                                    <th>類組</th>
                                    <th>評分狀態</th>
                                    <th style="width: 120px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="tosTeamBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="tosEmpty" class="alert alert-info mt-0" style="display: block;">
                <i class="fa-solid fa-info-circle me-2"></i>選擇左側評分時段後將自動顯示團隊列表。
            </div>
        </div>
    </div>
</div>

<!-- 評分 Modal：圖2版 — 左歷次建議、右團隊建議+評分表格 -->
<div class="modal fade" id="tosScoreModal" tabindex="-1" aria-labelledby="tosScoreModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tosScoreModalLabel">評分 — <span id="tosModalTeamName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body tos-modal-body-two-col">
                <input type="hidden" id="tosModalSfId" value="">
                <input type="hidden" id="tosModalTeamId" value="">
                <div class="row g-0 h-100">
                    <!-- 左：歷次建議 -->
                    <div class="col-md-5 tos-col-history">
                        <label class="form-label fw-bold tos-label-block">歷次建議</label>
                        <div id="tosModalHistoryBlock" class="tos-past-suggest-box" style="display: none;">
                            <div id="tosModalHistoryList" class="tos-history-list"></div>
                        </div>
                        <div id="tosModalHistoryEmpty" class="tos-past-suggest-box tos-past-suggest-empty">尚無歷次建議</div>
                    </div>
                    <!-- 右：團隊建議 + 評分 -->
                    <div class="col-md-7 tos-col-form">
                        <table class="table table-bordered tos-suggest-score-table mb-0">
                            <thead>
                                <tr>
                                    <th class="tos-th-suggest">團隊建議</th>
                                    <th class="tos-th-score">評分</th>
                                </tr>
                            </thead>
                            <tbody id="tosSuggestScoreTbody">
                                <tr class="tos-team-row">
                                    <td class="p-0 align-top">
                                        <textarea id="tosModalSuggest" class="form-control tos-suggest-cell" rows="3" placeholder="請輸入對該團隊的建議..."></textarea>
                                    </td>
                                    <td class="tos-score-cell align-top">
                                        <input type="number" id="tosModalScore" class="form-control" min="0" max="100" placeholder="0～100">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div id="tosStudentScores" class="d-none"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                <button type="button" class="btn btn-primary" id="tosModalSave">
                    <i class="fa-solid fa-save me-1"></i>儲存
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../js/teacher_online_scoring.js?v=<?= time() ?>"></script>
