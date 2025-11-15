<?php
/**
 * 里程碑管理頁面
 * 
 * 修改記錄：
 * 2025-01-XX XX:XX - 移除圖標、簡化配色、整合新增按鈕到篩選區域
 *   改動內容：移除所有圖標（除新增按鈕的加號），將新增按鈕整合到篩選區域的 header
 *   相關功能：頁面整體視覺設計、圖標顯示、按鈕布局
 *   方式：移除所有 <i> 標籤，將新增按鈕移到 filter-header，簡化視覺設計
 * 
 * 2025-01-XX XX:XX - 分離標題和新增按鈕，優化間距和篩選尺寸
 *   改動內容：將新增按鈕從標題區域分離到獨立區域，增加圖標與標題間距，進一步縮小篩選區域
 *   相關功能：頁面標題、新增按鈕區域、篩選區域
 *   方式：創建獨立的 action-header 區域，調整 gap 和 padding，縮小篩選區域所有尺寸
 * 
 * 2025-01-XX XX:XX - 優化標題和篩選區域，提升親和力
 *   改動內容：簡化標題設計，縮小篩選區域，確認表單按鈕順序（確定鍵在右邊）
 *   相關功能：頁面標題、篩選區域、表單按鈕
 *   方式：簡化視覺設計，縮小尺寸，使用 flex-end 確保確定按鈕在右側
 * 
 * 2025-01-XX XX:XX - 優化排版設計
 *   改動內容：重新組織卡片內容結構，添加資訊分組，改善視覺層次
 *   相關功能：里程碑卡片內容顯示
 *   方式：使用 info-group 分組相關資訊，優化時間資訊顯示格式
 * 
 * 2025-01-XX XX:XX - 美化版面設計
 *   改動內容：增強視覺效果，添加漸變背景、陰影、動畫效果
 *   相關功能：里程碑管理頁面整體美化
 *   方式：使用 CSS3 漸變、陰影、過渡動畫、響應式設計
 * 
 * 2025-01-XX XX:XX - 基本需求改為可選
 *   改動內容：將基本需求從必填改為選填，允許不關聯基本需求
 *   相關功能：里程碑新增/編輯表單
 *   方式：移除 required 屬性，後端允許 req_ID 為 0 或 NULL
 */

session_start();
require '../includes/pdo.php';

// 檢查權限：只有指導老師 (role_ID=4) 可以訪問
$role_ID = $_SESSION['role_ID'] ?? null;
$u_ID = $_SESSION['u_ID'] ?? null;

if (!$u_ID) {
    echo '<div class="alert alert-danger">請先登入</div>';
    exit;
}

// 檢查是否為指導老師
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM userrolesdata 
    WHERE ur_u_ID = ? AND role_ID = 4 AND user_role_status = 1
");
$stmt->execute([$u_ID]);
if (!$stmt->fetchColumn()) {
    echo '<div class="alert alert-danger">此頁面僅限指導老師使用</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<link rel="stylesheet" href="css/milestone.css?v=<?= time() ?>">

<div id="milestoneApp" class="milestone-container">
    <!-- 頁面標題 -->
    <div class="page-header">
        <h1 class="page-title">
            里程碑管理
        </h1>
    </div>

    <!-- 篩選區域 -->
    <div class="filter-section">
        <div class="filter-card">
            <div class="filter-header">
                <label class="filter-label">
                    篩選條件
                </label>
                <button class="btn-create-milestone" @click="showCreateModal = true">
                    <i class="fa-solid fa-plus"></i>
                    新增里程碑
                </button>
            </div>
            <div class="filter-controls">
                <select v-model="filters.team_ID" @change="loadMilestones" class="filter-select">
                    <option value="0">全部團隊</option>
                    <option v-for="team in teams" :key="team.team_ID" :value="team.team_ID">
                        {{ team.team_name || `團隊 ${team.team_ID}` }}
                    </option>
                </select>
                <select v-model="filters.req_ID" @change="loadMilestones" class="filter-select">
                    <option value="0">全部需求</option>
                    <option v-for="req in requirements" :key="req.req_ID" :value="req.req_ID">
                        {{ req.req_title }}
                    </option>
                </select>
                <select v-model="filters.status" @change="loadMilestones" class="filter-select">
                    <option value="-1">全部狀態</option>
                    <option value="0">進行中</option>
                    <option value="1">已完成</option>
                    <option value="2">已通過</option>
                </select>
            </div>
        </div>
    </div>

    <!-- 里程碑列表 -->
    <div class="milestones-grid" v-if="milestones.length > 0">
        <div v-for="milestone in filteredMilestones" 
             :key="milestone.ms_ID" 
             class="milestone-card"
             :class="getStatusClass(milestone.ms_status)"
             @click="selectMilestone(milestone)">
            <!-- 卡片頂部 -->
            <div class="card-header-section">
                <div class="status-badge" :class="getStatusBadgeClass(milestone.ms_status)">
                    {{ getStatusText(milestone.ms_status) }}
                </div>
                <div class="card-actions">
                    <button class="btn-icon" @click.stop="editMilestone(milestone)" title="編輯">編輯</button>
                    <button class="btn-icon btn-danger" @click.stop="deleteMilestone(milestone)" title="刪除">刪除</button>
                </div>
            </div>

            <!-- 卡片內容 -->
            <div class="card-content">
                <div>
                    <h3 class="milestone-title">{{ milestone.ms_title }}</h3>
                    <p class="milestone-desc" v-if="milestone.ms_desc">{{ milestone.ms_desc }}</p>
                </div>
                
                <!-- 資訊分組 -->
                <div class="info-group">
                    <!-- 關聯的基本需求 -->
                    <div class="requirement-link" v-if="milestone.req_title">
                        <span>{{ milestone.req_title }}</span>
                    </div>
                    <div class="requirement-link text-muted" v-else>
                        <span>未關聯基本需求</span>
                    </div>

                    <!-- 團隊資訊 -->
                    <div class="team-info" v-if="milestone.team_name">
                        <span>{{ milestone.team_name }}</span>
                    </div>
                </div>

                <!-- 時間資訊 -->
                <div class="time-info">
                    <div class="time-item">
                        <span><strong>開始：</strong>{{ formatDate(milestone.ms_start_d) }}</span>
                    </div>
                    <div class="time-item">
                        <span><strong>截止：</strong>{{ formatDate(milestone.ms_end_d) }}</span>
                    </div>
                </div>

                <!-- 完成資訊 -->
                <div class="completion-info" v-if="milestone.ms_completed_d">
                    <div>
                        <div><strong>完成時間：</strong>{{ formatDateTime(milestone.ms_completed_d) }}</div>
                        <div v-if="milestone.completer_name" style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                            完成者：{{ milestone.completer_name }}
                        </div>
                    </div>
                </div>

                <!-- 審核資訊 -->
                <div class="approval-info" v-if="milestone.ms_approved_d">
                    <div>
                        <div><strong>通過時間：</strong>{{ formatDateTime(milestone.ms_approved_d) }}</div>
                        <div v-if="milestone.approver_name" style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                            審核人：{{ milestone.approver_name }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- 卡片底部操作 -->
            <div class="card-footer" v-if="milestone.ms_status === 0">
                <button class="btn-action btn-complete" @click.stop="approveMilestone(milestone, 'complete')">
                    標記完成
                </button>
            </div>
            <div class="card-footer" v-if="milestone.ms_status === 1">
                <button class="btn-action btn-approve" @click.stop="approveMilestone(milestone, 'approve')">
                    審核通過
                </button>
            </div>
        </div>
    </div>

    <!-- 空狀態 -->
    <div class="empty-state" v-else>
        <div class="empty-icon">
            <i class="fa-solid fa-flag"></i>
        </div>
        <h3>尚無里程碑</h3>
        <p>點擊上方「新增里程碑」按鈕來建立第一個里程碑</p>
    </div>

    <!-- 新增/編輯里程碑 Modal -->
    <div class="modal-overlay" v-if="showCreateModal || showEditModal" @click="closeModal">
        <div class="modal-content" @click.stop>
            <div class="modal-header">
                <h3>
                    {{ showEditModal ? '編輯里程碑' : '新增里程碑' }}
                </h3>
                <button class="btn-close-modal" @click="closeModal">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="saveMilestone">
                    <div class="form-group">
                        <label>
                            關聯基本需求 <span class="text-muted">(選填)</span>
                        </label>
                        <select v-model="form.req_ID" class="form-control">
                            <option value="0">不關聯基本需求</option>
                            <option v-for="req in requirements" :key="req.req_ID" :value="req.req_ID">
                                {{ req.req_title }}
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            團隊 <span class="text-danger">*</span>
                        </label>
                        <select v-model="form.team_ID" required class="form-control">
                            <option value="0">請選擇團隊</option>
                            <option v-for="team in teams" :key="team.team_ID" :value="team.team_ID">
                                {{ team.team_name || `團隊 ${team.team_ID}` }}
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            里程碑標題 <span class="text-danger">*</span>
                        </label>
                        <input type="text" v-model="form.ms_title" required class="form-control" 
                               placeholder="例如：完成系統架構設計">
                    </div>

                    <div class="form-group">
                        <label>
                            里程碑說明
                        </label>
                        <textarea v-model="form.ms_desc" class="form-control" rows="3" 
                                  placeholder="詳細說明此里程碑的內容..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                開始時間 <span class="text-danger">*</span>
                            </label>
                            <input type="datetime-local" v-model="form.ms_start_d" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label>
                                截止時間 <span class="text-danger">*</span>
                            </label>
                            <input type="datetime-local" v-model="form.ms_end_d" required class="form-control">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-cancel" @click="closeModal">取消</button>
                        <button type="submit" class="btn-submit">
                            {{ showEditModal ? '更新' : '建立' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="js/milestone.js?v=<?= time() ?>"></script>

