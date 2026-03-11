<?php
session_start();
require '../includes/pdo.php';

// 權限檢查
$role_ID = $_SESSION['role_ID'] ?? null;
if (!in_array($role_ID, [1, 2])) {
    echo '<div class="alert alert-danger m-3">您沒有權限訪問此頁面</div>';
    exit;
}

// 狀態顯示映射（給 PHP 之後如果要用得到）
$statusDisplayMap = [
    0 => '休學',
    1 => '專題進行中',
    2 => '專題未通過',
    3 => '專題已通過',
    5 => '暑修中',
    6 => '暑修通過',
    7 => '寒修中',
    8 => '寒修通過'
];
?>

<link rel="stylesheet" href="css/admin_usermanage.css?v=<?= time() ?>">

<div class="um-container um-init-hidden" id="userManagementContent">
    <!-- 頂部導覽列（sticky） -->
    <header class="um-header">
        <div class="um-header-inner">
            <div class="um-header-left">
                <div class="um-breadcrumb">專題系統 / 管理端</div>
                <h1 class="um-page-title">帳號管理</h1>
            </div>
            <div class="um-header-actions">
                <input type="text" id="filterSearch" class="um-search-input" placeholder="搜尋帳號 / 姓名 / Email" form="filterForm">
                <button type="button" class="um-btn um-btn-outline" id="btnExportList">匯出名單</button>
                <button type="button" class="um-btn um-btn-primary" id="btnAddSingle">
                    <i class="fa-solid fa-user-plus"></i> 新增帳號
                </button>
            </div>
        </div>
    </header>

    <main class="um-main-content">
        <!-- 屆別切換 + 統計卡片 -->
        <section class="um-section um-section-stats">
            <div class="um-cohort-row">
                <span class="um-cohort-label">屆別切換</span>
                <div class="um-cohort-tabs" id="cohortTabs">
                    <!-- JS 動態產生 -->
                </div>
            </div>
            <div class="um-stats-grid" id="globalStatsRow">
                <div class="um-loading"><span class="spinner-border spinner-border-sm"></span> 載入中...</div>
            </div>
        </section>

        <!-- 篩選與資料總覽 + 指導老師負載 -->
        <section class="um-section um-section-filter-grid">
            <div class="um-filter-panel">
                <div class="um-panel-header">
                    <h2 class="um-panel-title">篩選與資料總覽</h2>
                    <p class="um-panel-desc">先選屆別，再透過角色、狀態與班級縮小範圍，避免歷屆資料混雜。</p>
                </div>
                <div class="um-filter-content">
                    <div class="um-filter-left">
                        <form id="filterForm" class="um-filter-form">
                            <div class="um-filter-group">
                                <label class="um-filter-label">快速篩選</label>
                                <div class="um-quick-filters">
                                    <button type="button" class="um-quick-filter-tag" data-filter="role_student">只看學生</button>
                                    <button type="button" class="um-quick-filter-tag" data-filter="status_doing">專題中</button>
                                    <button type="button" class="um-quick-filter-tag" data-filter="role_teacher">只看指導老師</button>
                                    <button type="button" class="um-quick-filter-tag" data-filter="role_class_teacher">班導</button>
                                    <button type="button" class="um-quick-filter-tag" data-filter="team_limit_reached">已達上限</button>
                                    <button type="button" class="um-quick-filter-tag" data-filter="no_team_limit">未設定上限</button>
                                </div>
                            </div>
                            <div class="um-filter-row">
                                <div class="um-filter-field">
                                    <label for="filterRole" class="um-filter-label">角色</label>
                                    <select id="filterRole" name="role_filter" class="um-select">
                                        <option value="">全部角色</option>
                                    </select>
                                </div>
                                <div class="um-filter-field">
                                    <label for="filterStatus" class="um-filter-label">狀態</label>
                                    <select id="filterStatus" name="status_filter" class="um-select">
                                        <option value="">全部狀態</option>
                                    </select>
                                </div>
                            </div>
                            <div class="um-filter-row">
                                <div class="um-filter-field">
                                    <label for="filterClass" class="um-filter-label">班級</label>
                                    <select id="filterClass" name="class_filter" class="um-select">
                                        <option value="">全部班級</option>
                                    </select>
                                </div>
                                <div class="um-filter-field">
                                    <label for="filterSort" class="um-filter-label">排序</label>
                                    <select id="filterSort" class="um-select">
                                        <option value="status">依狀態優先</option>
                                        <option value="class">依班級排序</option>
                                        <option value="name">依姓名排序</option>
                                        <option value="id">依帳號排序</option>
                                    </select>
                                </div>
                            </div>
                            <div class="um-filter-advanced">
                                <button type="button" class="um-advanced-toggle" id="toggleAdvancedFilters">
                                    <i class="fa-solid fa-chevron-down"></i> 進階篩選
                                </button>
                                <div class="um-advanced-filters um-advanced-hidden" id="advancedFilters">
                                    <div class="um-filter-field">
                                        <label for="filterCohort" class="um-filter-label">學級</label>
                                        <select id="filterCohort" name="cohort_filter" class="um-select">
                                            <option value="">全部學級</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="um-filter-actions">
                                <button type="submit" class="um-btn um-btn-primary">套用篩選</button>
                                <button type="button" class="um-btn um-btn-outline" id="clearFiltersBtn">清除條件</button>
                            </div>
                        </form>
                    </div>
                    <div class="um-filter-right">
                        <div class="um-class-distribution">
                            <h3 class="um-dist-title">班級分布</h3>
                            <div id="classDistributionList" class="um-dist-list">
                                <!-- JS 動態產生 -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="um-teacher-panel">
                <div class="um-panel-header">
                    <h2 class="um-panel-title">指導老師負載</h2>
                    <p class="um-panel-desc">老師 × 屆別表格，可直接看出各屆是否還能帶組。灰底「未開放」為該屆無學籍資料、綠底為可再帶、黃底為快到上限、紅底為超過上限。</p>
                </div>
                <div class="um-teacher-list" id="teacherLoadList">
                    <!-- JS 動態產生，若無資料則顯示提示 -->
                </div>
            </div>
        </section>

        <!-- 帳號列表 -->
        <section class="um-section um-section-table">
            <div class="um-table-header">
                <div class="um-table-header-left">
                    <h2 class="um-panel-title">帳號列表</h2>
                    <p class="um-table-meta" id="tableMeta">符合條件：<span id="filteredCount">0</span> 人</p>
                </div>
                <div class="um-table-header-actions">
                    <span class="um-selected-info">已選取 <span id="selectedCount">0</span> 人</span>
                    <button type="button" class="um-btn um-btn-outline um-btn-sm" id="selectAllBtn">全選</button>
                    <button type="button" class="um-btn um-btn-outline um-btn-sm" id="deselectAllBtn">取消全選</button>
                    <button type="button" class="um-btn um-btn-outline um-btn-sm" id="btnBatchImport">
                        <i class="fa-solid fa-file-import"></i> 批次匯入
                    </button>
                </div>
            </div>

            <div class="um-table-wrapper">
                <div id="userCardGridContainer">
                    <div class="um-loading um-loading-lg"><span class="spinner-border spinner-border-sm"></span> 正在載入使用者資料...</div>
                </div>
            </div>

            <div class="um-table-footer">
                <div class="um-footer-hint">排序建議：先屆別 → 再狀態 → 再班級 → 最後姓名</div>
                <div class="um-pagination" id="paginationArea">
                    <!-- 分頁可選實作 -->
                </div>
            </div>
        </section>
    </main>
</div>

<!-- 狀態統計的屆別明細 Modal -->
<div class="modal fade" id="statusCohortModal" tabindex="-1" aria-labelledby="statusCohortModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered um-modal-cohort">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="statusCohortModalLabel">
                    <i class="fa-solid fa-chart-pie me-2"></i>
                    <span id="modalStatusName"></span> - 屆別統計
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>
            <div class="modal-body">
                <div id="cohortStatsContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

<script src="js/admin_usermanage.js?v=<?= time() ?>"></script>
