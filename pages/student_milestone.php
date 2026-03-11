<?php
/**
 * 學生端里程碑頁面
 * 
 * 修改記錄：
 * 2025-11-16 - 重新設計學生端里程碑頁面
 *   改動內容：改為簡潔風格，添加完成按鈕、優先級顯示、通知功能
 *   相關功能：學生里程碑查看和完成操作
 *   方式：Vue.js 3 組件，簡潔 UI 設計
 */

session_start();
require '../includes/pdo.php';

// 檢查權限：只有學生 (role_ID=6) 可以訪問
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

if ($role_ID != 6) {
    echo '<div class="alert alert-danger">此頁面僅限學生使用</div>';
    exit;
}

// 獲取學生所屬的團隊
// 修改日期：2025-11-16
// 改動內容：修正欄位名稱，使用兼容性查詢（先嘗試 team_u_ID，失敗則嘗試 u_ID）
// 相關功能：獲取學生所屬團隊
// 方式：使用 try-catch 處理不同版本的資料表結構
try {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.team_ID, t.team_project_name as team_name
        FROM teamdata t
        JOIN teammember tm ON t.team_ID = tm.team_ID
        WHERE tm.team_u_ID = ? AND t.team_status = 1
        LIMIT 1
    ");
    $stmt->execute([$u_ID]);
} catch (Exception $e) {
    // 如果失敗，嘗試使用舊的欄位名稱
    $stmt = $conn->prepare("
        SELECT DISTINCT t.team_ID, t.team_project_name as team_name
        FROM teamdata t
        JOIN teammember tm ON t.team_ID = tm.team_ID
        WHERE tm.u_ID = ? AND t.team_status = 1
        LIMIT 1
    ");
    $stmt->execute([$u_ID]);
}
$studentTeam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$studentTeam) {
    echo '<div class="alert alert-warning">您尚未加入任何團隊</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<link rel="stylesheet" href="css/student_milestone.css?v=<?= time() ?>" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="css/student_milestone.css?v=<?= time() ?>"></noscript>

<div id="studentMilestoneApp" class="student-milestone-container">
    <!-- 頁面標題與統整資料同一列 -->
    <div class="milestone-header-row">
        <div class="page-header">
            <h1 class="page-title">里程碑</h1>
            <div class="team-info">
                <span class="team-label">團隊：</span>
                <span class="team-name"><?= htmlspecialchars($studentTeam['team_name']) ?></span>
            </div>
        </div>
        <!-- 統整資料（進度總覽） -->
        <div class="progress-section">
        <div class="progress-card">
            <div class="progress-header">
                <div class="progress-label">總進度</div>
                <button class="btn-gantt" @click="showGanttChart">
                    <i class="fa-solid fa-chart-gantt"></i>
                    顯示甘特圖
                </button>
            </div>
            <div class="progress-value">
                <span class="current">{{ completedCount }}</span>
                <span class="separator">/</span>
                <span class="total">{{ milestones.length }}</span>
            </div>
            <div class="progress-bar-wrapper">
                <div class="progress-bar" :style="{ width: progressPercentage + '%' }"></div>
            </div>
        </div>
    </div>
    </div>

    <!-- 里程碑列表 -->
    <div class="milestones-list" v-if="milestones && milestones.length > 0">
        <div 
            v-for="(milestone, index) in sortedMilestones" 
            :key="milestone.ms_ID"
            class="milestone-card"
            :class="getStatusClass(milestone.ms_status)">
            
            <!-- 超級緊急圖釘標示 -->
            <div class="pin-badge" v-if="Number(milestone.ms_priority) === 3" title="超級緊急置頂">
                <i class="fa-solid fa-thumbtack"></i>
            </div>
            <!-- 優先級標示 -->
            <div class="priority-badge" :class="getPriorityClass(milestone.ms_priority)">
                {{ getPriorityText(milestone.ms_priority) }}
            </div>

            <!-- 里程碑內容 -->
            <div class="milestone-content">
                <div class="milestone-header">
                    <h3 class="milestone-title">{{ milestone.ms_title }}</h3>
                    <div class="milestone-status" :class="getStatusBadgeClass(milestone.ms_status)">
                        {{ getStatusText(milestone.ms_status) }}
                    </div>
                </div>

                <p class="milestone-desc" v-if="milestone.ms_desc">{{ milestone.ms_desc }}</p>

                <!-- 里程碑資訊 -->
                <div class="milestone-info">
                    <div class="info-item" v-if="milestone.req_title">
                        <span class="info-label">關聯需求：</span>
                        <span class="info-value">{{ milestone.req_title }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">開始時間：</span>
                        <span class="info-value">{{ formatDate(milestone.ms_start_d) }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">截止時間：</span>
                        <span class="info-value">{{ formatDate(milestone.ms_end_d) }}</span>
                    </div>
                </div>

                <!-- 完成資訊 -->
                <div class="completion-info" v-if="milestone.ms_status === 3 || milestone.ms_status === 4">
                    <div class="completion-badge" v-if="milestone.ms_status === 4">
                        <span>等待審查中</span>
                        <span v-if="milestone.ms_completed_d" class="completion-date">
                            提交時間：{{ formatDateTime(milestone.ms_completed_d) }}
                        </span>
                    </div>
                    <div class="completion-badge approved" v-if="milestone.ms_status === 3">
                        <span>已完成</span>
                        <span v-if="milestone.ms_approved_d" class="completion-date">
                            通過時間：{{ formatDateTime(milestone.ms_approved_d) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- 操作按鈕 -->
            <div class="milestone-actions">
                <button 
                    v-if="showAcceptButton(milestone)"
                    class="btn-accept" 
                    :class="{ 'btn-disabled': isActionDisabled(milestone) }"
                    :disabled="isActionDisabled(milestone)"
                    @click.stop="acceptMilestone(milestone)">
                    {{ milestone.isAccepting ? '接取中...' : '接任務' }}
                </button>
                <button 
                    v-if="showCompleteButton(milestone)"
                    class="btn-complete" 
                    :class="{ 'btn-disabled': isActionDisabled(milestone) }"
                    :disabled="isActionDisabled(milestone)"
                    @click.stop="completeMilestone(milestone)">
                    {{ getActionButtonText(milestone) }}
                </button>
                <button 
                    v-if="milestone.ms_status === 3 || milestone.ms_status === 4"
                    class="btn-complete btn-disabled" 
                    disabled>
                    {{ getActionButtonText(milestone) }}
                </button>
            </div>
        </div>
    </div>

    <!-- 空狀態 -->
    <div class="empty-state" v-else>
        <div class="empty-text">目前還沒有里程碑</div>
        <div class="empty-hint">等待指導老師設定里程碑...</div>
    </div>
</div>

<script src="js/student_milestone.js?v=<?= time() ?>"></script>
