<!-- 進度總覽 -->
            <div class="progress-section">
                <div class="progress-card">
                    <div class="progress-header">
                        <div class="progress-label">總進度</div>
                        <button class="btn-gantt" @click="toggleGanttView">
                            <i class="fa-solid fa-chart-gantt"></i>
                            {{ showGanttView ? '顯示列表' : '顯示甘特圖' }}
                        </button>
                    </div>
                    <div class="progress-value">
                        <span class="current">{{ studentMilestoneCompletedCount }}</span>
                        <span class="separator">/</span>
                        <span class="total">{{ studentMilestones.length }}</span>
                    </div>
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar" :style="{ width: studentMilestoneProgressPercentage + '%' }"></div>
                    </div>
                </div>
            </div>

            <!-- 甘特圖視圖 -->
            <div v-if="showGanttView" class="gantt-content" v-show="studentMilestones && studentMilestones.length > 0">
                <div class="gantt-chart">
                    <div class="gantt-timeline">
                        <div class="gantt-row gantt-header-row">
                            <div class="gantt-task-name">里程碑</div>
                            <div class="gantt-bars-container">
                                <div class="gantt-time-scale">
                                    <div v-for="date in ganttTimeScale" :key="date" class="gantt-time-marker">
                                        {{ formatGanttDateShort(date) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div 
                            v-for="milestone in sortedStudentMilestones" 
                            :key="milestone.ms_ID"
                            class="gantt-row">
                            <div class="gantt-task-name">
                                <div class="task-title">{{ milestone.ms_title }}</div>
                                <div class="task-meta">
                                    <span class="task-status" :class="getStudentMilestoneStatusClass(milestone.ms_status)">
                                        {{ getStudentMilestoneStatusText(milestone.ms_status) }}
                                    </span>
                                    <span class="task-priority" :class="getStudentMilestonePriorityClass(milestone.ms_priority)">
                                        {{ getStudentMilestonePriorityText(milestone.ms_priority) }}
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
                                        <div class="bar-label">{{ milestone.ms_title }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 里程碑列表 -->
            <div class="milestones-list" v-if="!showGanttView && studentMilestones && studentMilestones.length > 0">
                <div 
                    v-for="(milestone, index) in sortedStudentMilestones" 
                    :key="milestone.ms_ID"
                    class="milestone-card"
                    :class="getStudentMilestoneStatusClass(milestone.ms_status)">
                    
                    <!-- 超級緊急圖釘標示 -->
                    <div class="pin-badge" v-if="Number(milestone.ms_priority) === 3" title="超級緊急置頂">
                        <i class="fa-solid fa-thumbtack"></i>
                    </div>
                    <!-- 優先級標示 -->
                    <div class="priority-badge" :class="getStudentMilestonePriorityClass(milestone.ms_priority)">
                        {{ getStudentMilestonePriorityText(milestone.ms_priority) }}
                    </div>

                    <!-- 里程碑內容 -->
                    <div class="milestone-content">
                        <div class="milestone-header">
                            <h3 class="milestone-title">{{ milestone.ms_title }}</h3>
                            <div class="milestone-status" :class="getStudentMilestoneStatusBadgeClass(milestone.ms_status)">
                                {{ getStudentMilestoneStatusText(milestone.ms_status) }}
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
                                <span class="info-value">{{ formatStudentMilestoneDate(milestone.ms_start_d) }}</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">截止時間：</span>
                                <span class="info-value">{{ formatStudentMilestoneDate(milestone.ms_end_d) }}</span>
                            </div>
                        </div>

                        <!-- 完成資訊 -->
                        <div class="completion-info" v-if="milestone.ms_status === 3 || milestone.ms_status === 4">
                            <div class="completion-badge" v-if="milestone.ms_status === 4">
                                <span>等待審查中</span>
                                <span v-if="milestone.ms_completed_d" class="completion-date">
                                    提交時間：{{ formatStudentMilestoneDateTime(milestone.ms_completed_d) }}
                                </span>
                            </div>
                            <div class="completion-badge approved" v-if="milestone.ms_status === 3">
                                <span>已完成</span>
                                <span v-if="milestone.ms_approved_d" class="completion-date">
                                    通過時間：{{ formatStudentMilestoneDateTime(milestone.ms_approved_d) }}
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
            <div class="empty-state" v-if="!showGanttView && (!studentMilestones || studentMilestones.length === 0)">
                <div class="empty-text">目前還沒有里程碑</div>
                <div class="empty-hint">等待指導老師設定里程碑...</div>
            </div>
            <div class="empty-state" v-if="showGanttView && (!studentMilestones || studentMilestones.length === 0)">
                <div class="empty-icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div class="empty-text">目前沒有里程碑資料</div>
            </div>