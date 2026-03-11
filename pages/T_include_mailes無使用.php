<div id="milestoneApp" class="milestone-container">
    <!-- 篩選區域 -->
    <div class="filter-section">
        <div class="filter-card">
            <div class="filter-header">
                <label class="filter-label">
                    篩選條件
                </label>
                <div style="display: flex; gap: 0.75rem;">
                    <button class="btn-gantt" @click="toggleGanttView">
                        <i class="fa-solid fa-chart-gantt"></i>
                        {{ showGanttView ? '顯示列表' : '顯示甘特圖' }}
                    </button>
                    <button class="btn-create-milestone" @click="openCreateMilestoneModal">
                        <i class="fa-solid fa-plus"></i>
                        新增任務
                    </button>
                </div>
            </div>
            <div class="filter-controls">
                <select v-model="milestoneFilters.team_ID" @change="loadMilestones" class="filter-select">
                    <option value="0">全部團隊</option>
                    <option v-for="team in milestoneTeams" :key="team.team_ID" :value="team.team_ID">
                        {{ team.team_name || `團隊 ${team.team_ID}` }}
                    </option>
                </select>
                <select v-model="milestoneFilters.priority" @change="loadMilestones" class="filter-select">
                    <option value="-1">優先級</option>
                    <option value="0">一般</option>
                    <option value="1">重要</option>
                    <option value="2">緊急</option>
                    <option value="3">超級緊急</option>
                </select>
                <select v-model="milestoneFilters.status" @change="loadMilestones" class="filter-select">
                    <option value="-1">全部狀態</option>
                    <option value="0">還未開始</option>
                    <option value="1">進行中</option>
                    <option value="4">待審核</option>
                    <option value="2">退回</option>
                    <option value="3">已完成</option>
                </select>
            </div>
        </div>
    </div>

    <!-- 甘特圖視圖 -->
    <div v-if="showGanttView" class="gantt-content" style="min-height: 400px;">
        <div class="gantt-chart" v-if="filteredMilestoneList && filteredMilestoneList.length > 0">
            <div class="gantt-timeline">
                <div class="gantt-row gantt-header-row">
                    <div class="gantt-task-name">任務公佈欄</div>
                    <div class="gantt-bars-container">
                        <div class="gantt-time-scale">
                            <div v-for="date in ganttTimeScale" :key="date" class="gantt-time-marker">
                                {{ formatGanttDateShort(date) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    v-for="milestone in filteredMilestoneList"
                    :key="milestone.ms_ID"
                    class="gantt-row">
                    <div class="gantt-task-name">
                        <div class="task-title">{{ milestone.ms_title }}</div>
                        <div class="task-meta">
                            <span v-if="milestone.team_name" class="task-team">{{ milestone.team_name }}</span>
                            <span class="task-status" :class="getMilestoneStatusBarClass(milestone.ms_status)">
                                {{ getMilestoneStatusText(milestone.ms_status) }}
                            </span>
                            <span class="task-priority" :class="getMilestonePriorityClass(milestone.ms_priority || 0)">
                                {{ getMilestonePriorityText(milestone.ms_priority || 0) }}
                            </span>
                        </div>
                    </div>
                    <div class="gantt-bars-container">
                        <div class="gantt-bar-wrapper" :style="getGanttBarStyle(milestone)">
                            <div
                                class="gantt-bar"
                                :class="getGanttBarClass(milestone)"
                                :style="getGanttBarPosition(milestone)"
                                :title="getGanttBarTooltip(milestone)"
                                @mouseenter="showGanttTooltip($event, milestone)"
                                @mouseleave="hideGanttTooltip($event)">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- 甘特圖 Tooltip -->
        <div v-if="ganttTooltipElement" ref="ganttTooltipRef"></div>
        <!-- 甘特圖空狀態 -->
        <div v-if="!milestoneList || milestoneList.length === 0" class="empty-state">
            <div class="empty-icon">
                <i class="fa-solid fa-chart-gantt"></i>
            </div>
            <h3>尚無任務</h3>
            <p>點擊上方「新增任務」按鈕來建立第一個任務公佈欄</p>
        </div>
    </div>

    <!-- 里程碑列表（長條顯示） -->
    <div class="milestones-list" v-if="!showGanttView && milestoneList.length > 0">
        <div v-for="milestone in filteredMilestoneList"
            :key="milestone.ms_ID"
            class="milestone-bar"
            :class="getMilestoneStatusBarClass(milestone.ms_status)"
            @click="showMilestoneDetail(milestone)">
            <div class="bar-content">
                <div class="bar-header">
                    <div class="bar-team" v-if="milestone.team_name">
                        {{ milestone.team_name }}
                    </div>
                    <div class="bar-priority-right">
                        <span class="priority-badge" :class="getMilestonePriorityClass(milestone.ms_priority || 0)">
                            {{ getMilestonePriorityText(milestone.ms_priority || 0) }}
                        </span>
                    </div>
                </div>
                <div class="bar-label">{{ milestone.ms_title }}</div>
            </div>
        </div>
    </div>

    <!-- 空狀態 -->
    <div class="empty-state" v-if="!showGanttView && milestoneList.length === 0">
        <div class="empty-icon">
            <i class="fa-solid fa-flag"></i>
        </div>
        <h3>尚無任務</h3>
        <p>點擊上方「新增任務」按鈕來建立第一個任務公佈欄</p>
    </div>
    <div class="empty-state" v-if="showGanttView && milestoneList.length === 0">
        <div class="empty-icon">
            <i class="fa-solid fa-chart-gantt"></i>
        </div>
        <h3>尚無任務</h3>
        <p>點擊上方「新增任務」按鈕來建立第一個任務公佈欄</p>
    </div>

    <!-- 里程碑詳細資訊 Modal -->
    <teleport to="body">

        <div class="milestone-detail-modal" v-if="selectedMilestone" @click.self="closeMilestoneDetail">
            <div class="milestone-detail-content">
                <div class="milestone-detail-header">
                    <div class="status-badges">
                        <span class="status-badge" :class="getMilestoneStatusBadgeClass(selectedMilestone.ms_status)">
                            {{ getMilestoneStatusText(selectedMilestone.ms_status) }}
                        </span>
                        <span class="priority-badge" :class="getMilestonePriorityClass(selectedMilestone.ms_priority || 0)">
                            {{ getMilestonePriorityText(selectedMilestone.ms_priority || 0) }}
                        </span>
                    </div>
                    <div class="modal-actions">
                        <button class="btn-icon" @click.stop="editMilestone(selectedMilestone)" title="編輯">編輯</button>
                        <button class="btn-icon btn-danger" @click.stop="deleteMilestone(selectedMilestone)" title="刪除">刪除</button>
                    </div>
                </div>

                <div class="milestone-detail-body">
                    <!-- 團隊資訊（最上方） -->
                    <div class="team-info" v-if="selectedMilestone.team_name">
                        <span>{{ selectedMilestone.team_name }}</span>
                    </div>

                    <!-- 基本需求（團隊下方） -->
                    <div class="requirement-link" v-if="selectedMilestone.req_title">
                        <span>{{ selectedMilestone.req_title }}</span>
                    </div>
                    <div class="requirement-link text-muted" v-else>
                        <span>未關聯最低專題要求</span>
                    </div>

                    <!-- 標題（基本需求下方） -->
                    <h3 class="milestone-title">{{ selectedMilestone.ms_title }}</h3>

                    <!-- 說明（標題下方，若無說明也顯示欄位） -->
                    <p class="milestone-desc" v-if="selectedMilestone.ms_desc">{{ selectedMilestone.ms_desc }}</p>
                    <p class="milestone-desc text-muted" v-else>無說明</p>

                    <!-- 時間資訊 -->
                    <div class="time-info">
                        <div class="time-item">
                            <span><strong>開始：</strong>{{ formatMilestoneDate(selectedMilestone.ms_start_d) }}</span>
                        </div>
                        <div class="time-item">
                            <span><strong>截止：</strong>{{ formatMilestoneDate(selectedMilestone.ms_end_d) }}</span>
                        </div>
                    </div>

                    <!-- 完成資訊 -->
                    <div class="completion-info" v-if="selectedMilestone.ms_completed_d">
                        <div>
                            <div><strong>完成時間：</strong>{{ formatMilestoneDateTime(selectedMilestone.ms_completed_d) }}</div>
                            <div v-if="selectedMilestone.completer_name" style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                完成者：{{ selectedMilestone.completer_name }}
                            </div>
                        </div>
                    </div>

                    <!-- 審核資訊 -->
                    <div class="approval-info" v-if="selectedMilestone.ms_approved_d">
                        <div>
                            <div><strong>通過時間：</strong>{{ formatMilestoneDateTime(selectedMilestone.ms_approved_d) }}</div>
                            <div v-if="selectedMilestone.approver_name" style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">
                                審核人：{{ selectedMilestone.approver_name }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 卡片底部操作 -->
                <!-- 還未開始狀態顯示 -->
                <div class="milestone-detail-footer" v-if="Number(selectedMilestone.ms_status) === 0">
                    <div class="status-badge" :class="getMilestoneStatusBadgeClass(selectedMilestone.ms_status)" style="width: 100%; justify-content: center;">
                        {{ getMilestoneStatusText(selectedMilestone.ms_status) }}
                    </div>
                </div>
                <!-- 待審核狀態顯示完成和退回按鈕 -->
                <div class="milestone-detail-footer" v-if="Number(selectedMilestone.ms_status) === 4">
                    <button class="btn-action btn-approve" @click.stop="approveMilestone(selectedMilestone, 'approve')">
                        完成
                    </button>
                    <button class="btn-action btn-reject" style="margin-top:0.5rem" @click.stop="approveMilestone(selectedMilestone, 'reject')">
                        退回
                    </button>
                </div>

                <div class="milestone-detail-footer" v-if="Number(selectedMilestone.ms_status) !== 0 && Number(selectedMilestone.ms_status) !== 4">
                    <button class="btn-close" @click="closeMilestoneDetail">關閉</button>
                </div>
            </div>
        </div>

        <!-- 新增/編輯里程碑 Modal -->
        <div class="modal fade" id="milestoneEditModal" tabindex="-1"
            :aria-labelledby="showEditMilestoneModal ? 'editMilestoneModalLabel' : 'createMilestoneModalLabel'"
            aria-hidden="true" style="display: none;">
            <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" :id="showEditMilestoneModal ? 'editMilestoneModalLabel' : 'createMilestoneModalLabel'">
                            {{ showEditMilestoneModal ? '編輯里程碑' : '新增里程碑' }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" @click="closeMilestoneModal" aria-label="關閉"></button>
                    </div>
                    <div class="modal-body">
                        <form id="milestoneForm" @submit.prevent="saveMilestone">
                            <div class="form-group mb-3">
                                <label class="form-label">
                                    關聯最低專題要求 <span class="text-muted">(選填)</span>
                                </label>
                                <select v-model="milestoneForm.req_ID" class="form-control">
                                    <option value="0">不關聯最低專題要求</option>
                                    <option v-for="req in milestoneRequirements" :key="req.req_ID" :value="req.req_ID">
                                        {{ req.req_title }}
                                    </option>
                                </select>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">
                                    團隊 <span class="text-danger">*</span>
                                </label>
                                <select v-model="milestoneForm.team_ID" required class="form-control">
                                    <option value="0">請選擇團隊</option>
                                    <option v-for="team in milestoneTeams" :key="team.team_ID" :value="team.team_ID">
                                        {{ team.team_name || `團隊 ${team.team_ID}` }}
                                    </option>
                                </select>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">
                                    任務標題 <span class="text-danger">*</span>
                                </label>
                                <input type="text" v-model="milestoneForm.ms_title" required class="form-control"
                                    placeholder="例如：完成系統架構設計">
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">
                                    任務說明
                                </label>
                                <textarea v-model="milestoneForm.ms_desc" class="form-control" rows="3"
                                    placeholder="詳細說明此任務的內容..."></textarea>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            開始時間 <span class="text-danger">*</span>
                                        </label>
                                        <input type="datetime-local" v-model="milestoneForm.ms_start_d" required class="form-control"
                                            @change="validateMilestoneTimeRange" @input="validateMilestoneTimeRange">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">
                                            截止時間 <span class="text-danger">*</span>
                                        </label>
                                        <input type="datetime-local" v-model="milestoneForm.ms_end_d" required class="form-control"
                                            @change="validateMilestoneTimeRange" @input="validateMilestoneTimeRange">
                                        <small v-if="milestoneTimeError" class="text-danger" style="display: block; margin-top: 0.25rem;">
                                            {{ milestoneTimeError }}
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">
                                    優先級
                                </label>
                                <select v-model="milestoneForm.ms_priority" class="form-control">
                                    <option value="0">一般</option>
                                    <option value="1">重要</option>
                                    <option value="2">緊急</option>
                                    <option value="3">超級緊急</option>
                                </select>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" @click="closeMilestoneModal">取消</button>
                        <button type="button" class="btn btn-primary" @click="saveMilestone">
                            {{ showEditMilestoneModal ? '更新' : '建立' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</div>