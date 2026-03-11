<?php
session_start();
require '../includes/pdo.php';

// 檢查登入狀態
if (!isset($_SESSION['u_ID'])) {
    echo '<div class="alert alert-danger m-3">請先登入</div>';
    exit;
}
?>

<link rel="stylesheet" href="css/announcement.css?v=<?= time() ?>">

<div class="announcement-container" id="announcementContent" style="visibility:hidden;">
    <!-- 頁面標題列 -->
    <div class="announcement-page-header">
        <div class="announcement-page-title">
            <h1>
                <i class="fa-solid fa-bullhorn me-2"></i>公告中心
            </h1>
            <p class="announcement-page-subtitle">
                查看最新公告與通知資訊
            </p>
        </div>

        <div class="announcement-page-actions">
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="filterType" id="filterAll" value="" checked>
                <label class="btn btn-outline-primary" for="filterAll">
                    <i class="fa-solid fa-list me-1"></i>全部
                </label>

                <input type="radio" class="btn-check" name="filterType" id="filterAnnouncement" value="ANNOUNCEMENT">
                <label class="btn btn-outline-primary" for="filterAnnouncement">
                    <i class="fa-solid fa-bullhorn me-1"></i>公告
                </label>

                <input type="radio" class="btn-check" name="filterType" id="filterSystem" value="SYSTEM_NOTICE">
                <label class="btn btn-outline-primary" for="filterSystem">
                    <i class="fa-solid fa-bell me-1"></i>系統通知
                </label>

                <input type="radio" class="btn-check" name="filterType" id="filterReminder" value="REMINDER">
                <label class="btn btn-outline-primary" for="filterReminder">
                    <i class="fa-solid fa-clock me-1"></i>提醒
                </label>
            </div>
            <button class="btn btn-outline-secondary btn-sm" id="refreshBtn">
                <i class="fa-solid fa-sync-alt me-1"></i>重新載入
            </button>
        </div>
    </div>

    <!-- 統計卡片 -->
    <div class="announcement-stats-row">
        <div class="announcement-stat-card">
            <div class="stat-icon stat-icon-all">
                <i class="fa-solid fa-list"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">總公告數</div>
                <div class="stat-value" id="statTotal">0</div>
            </div>
        </div>
        <div class="announcement-stat-card">
            <div class="stat-icon stat-icon-unread">
                <i class="fa-solid fa-envelope"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">未讀公告</div>
                <div class="stat-value" id="statUnread">0</div>
            </div>
        </div>
        <div class="announcement-stat-card">
            <div class="stat-icon stat-icon-announcement">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">一般公告</div>
                <div class="stat-value" id="statAnnouncement">0</div>
            </div>
        </div>
        <div class="announcement-stat-card">
            <div class="stat-icon stat-icon-notice">
                <i class="fa-solid fa-bell"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">系統通知</div>
                <div class="stat-value" id="statNotice">0</div>
            </div>
        </div>
    </div>

    <!-- 公告列表 -->
    <div class="announcement-list-container">
        <div id="announcementListContent">
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">載入中...</span>
                </div>
                <p class="text-muted mt-3 mb-0">正在載入公告...</p>
            </div>
        </div>
    </div>

    <!-- 分頁 -->
    <div class="announcement-pagination" id="announcementPagination" style="display: none;">
        <!-- 分頁按鈕將由 JavaScript 動態生成 -->
    </div>
</div>

<!-- 公告詳情 Modal -->
<div class="modal fade" id="announcementDetailModal" tabindex="-1" aria-labelledby="announcementDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="announcementDetailModalLabel">
                    <i class="fa-solid fa-file-lines me-2"></i>公告詳情
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>
            <div class="modal-body" id="announcementDetailContent">
                <!-- 詳情內容將由 JavaScript 動態載入 -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

<script src="js/announcement.js?v=<?= time() ?>"></script>




