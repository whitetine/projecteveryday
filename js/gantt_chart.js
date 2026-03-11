/**
 * 甘特圖頁面 - Vue.js 應用
 * 
 * 修改記錄：
 * 2025-11-18 - 創建甘特圖頁面邏輯
 *   改動內容：顯示里程碑甘特圖，支持學生和老師查看
 *   相關功能：里程碑甘特圖視覺化
 *   方式：Vue.js 3 API
 */

if (!window._ganttAppInitialized) {
    window._ganttAppInitialized = true;
    
    function cleanupGanttApp() {
        if (window.ganttApp && typeof window.ganttApp.unmount === 'function') {
            try {
                window.ganttApp.unmount();
                window.ganttApp = null;
            } catch (e) {
                console.warn('卸載 gantt app 時出錯:', e);
            }
        }
        window._ganttAppInitialized = false;
    }

    cleanupGanttApp();

    const oldHandler = window._ganttCleanupHandler;
    if (oldHandler) {
        window.removeEventListener('pageBeforeUnload', oldHandler);
    }
    window._ganttCleanupHandler = cleanupGanttApp;
    window.addEventListener('pageBeforeUnload', cleanupGanttApp);

    const mountEl = document.querySelector('#ganttApp');
    if (!mountEl) {
        console.warn('找不到 #ganttApp 元素');
        window._ganttAppInitialized = false;
    } else {
        const { createApp } = Vue;

        const ganttApp = createApp({
            data() {
                return {
                    milestones: [],
                    teams: [],
                    selectedTeam: 0,
                    role_ID: window.GANTT_CONFIG?.role_ID || 0,
                    team_ID: window.GANTT_CONFIG?.team_ID || 0,
                    timeScale: [],
                    startDate: null,
                    endDate: null,
                    tooltipElement: null,
                    tooltipTimer: null,
                    currentTooltipMilestone: null,
                    tooltipTargetElement: null,
                    selectedMilestone: null
                };
            },
            computed: {
                sortedMilestones() {
                    return [...this.milestones].sort((a, b) => {
                        const startA = new Date(a.ms_start_d || a.ms_created_d);
                        const startB = new Date(b.ms_start_d || b.ms_created_d);
                        return startA - startB;
                    });
                }
            },
            mounted() {
                if (this.role_ID === 4) {
                    this.loadTeams();
                } else {
                    this.loadGanttData();
                }
            },
            
            beforeUnmount() {
                // 組件卸載前清除定時器
                this.hideTooltip();
            },
            
            methods: {
                async loadTeams() {
                    try {
                        const response = await fetch('api.php?do=get_teams');
                        if (!response.ok) throw new Error('載入失敗');
                        const data = await response.json();
                        // 處理API返回格式
                        if (Array.isArray(data)) {
                            this.teams = data;
                        } else if (data.ok && Array.isArray(data.teams)) {
                            this.teams = data.teams;
                        } else if (Array.isArray(data.data)) {
                            this.teams = data.data;
                        } else {
                            this.teams = [];
                        }
                    } catch (error) {
                        console.error('載入團隊失敗:', error);
                        this.teams = [];
                    }
                },
                
                async loadGanttData() {
                    try {
                        let url = 'api.php?do=get_gantt_data';
                        if (this.role_ID === 4 && this.selectedTeam > 0) {
                            url += `&team_ID=${this.selectedTeam}`;
                        } else if (this.role_ID === 6 && this.team_ID > 0) {
                            url += `&team_ID=${this.team_ID}`;
                        }
                        
                        const response = await fetch(url);
                        if (!response.ok) throw new Error('載入失敗');
                        const data = await response.json();
                        
                        // 處理API返回格式：可能是 {ok: true, milestones: [...]} 或直接是 {milestones: [...]}
                        if (data.ok && data.milestones) {
                            this.milestones = data.milestones || [];
                            this.startDate = data.startDate;
                            this.endDate = data.endDate;
                        } else if (data.milestones) {
                            this.milestones = data.milestones || [];
                            this.startDate = data.startDate;
                            this.endDate = data.endDate;
                        } else {
                            this.milestones = [];
                        }
                        this.generateTimeScale();
                    } catch (error) {
                        console.error('載入甘特圖資料失敗:', error);
                        this.milestones = [];
                    }
                },
                
                generateTimeScale() {
                    if (!this.startDate || !this.endDate) {
                        // 如果沒有日期範圍，從里程碑中計算
                        const dates = this.milestones
                            .map(m => [m.ms_start_d, m.ms_end_d])
                            .flat()
                            .filter(d => d)
                            .map(d => new Date(d));
                        
                        if (dates.length === 0) {
                            this.timeScale = [];
                            return;
                        }
                        
                        this.startDate = new Date(Math.min(...dates));
                        this.endDate = new Date(Math.max(...dates));
                    }
                    
                    const start = new Date(this.startDate);
                    const end = new Date(this.endDate);
                    const days = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
                    const scaleDays = Math.max(30, Math.min(90, days));
                    
                    this.timeScale = [];
                    const current = new Date(start);
                    for (let i = 0; i <= scaleDays; i += 7) {
                        const date = new Date(current);
                        date.setDate(date.getDate() + i);
                        if (date <= end) {
                            this.timeScale.push(date.toISOString());
                        }
                    }
                },
                
                getBarStyle(milestone) {
                    if (!this.startDate || !this.endDate || this.timeScale.length === 0) {
                        return {};
                    }
                    
                    const start = new Date(this.startDate);
                    const end = new Date(this.endDate);
                    const totalDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
                    const containerWidth = this.timeScale.length * 100; // 假設每個時間標記100px寬
                    
                    return {
                        width: `${containerWidth}px`
                    };
                },
                
                getBarPosition(milestone) {
                    if (!this.startDate || !this.endDate) {
                        return { left: '0%', width: '0%' };
                    }
                    
                    // 如果沒有開始時間，使用結束時間或創建時間
                    let barStart = milestone.ms_start_d;
                    if (!barStart) {
                        barStart = milestone.ms_end_d || milestone.ms_created_d;
                    }
                    
                    // 如果沒有結束時間，使用開始時間或創建時間
                    let barEnd = milestone.ms_end_d;
                    if (!barEnd) {
                        barEnd = milestone.ms_start_d || milestone.ms_created_d;
                    }
                    
                    if (!barStart || !barEnd) {
                        return { left: '0%', width: '0%' };
                    }
                    
                    const start = new Date(this.startDate);
                    const end = new Date(this.endDate);
                    const barStartDate = new Date(barStart);
                    const barEndDate = new Date(barEnd);
                    
                    const totalDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
                    const daysFromStart = Math.max(0, Math.ceil((barStartDate - start) / (1000 * 60 * 60 * 24)));
                    const barDuration = Math.max(1, Math.ceil((barEndDate - barStartDate) / (1000 * 60 * 60 * 24)));
                    
                    const leftPercent = (daysFromStart / totalDays) * 100;
                    const widthPercent = (barDuration / totalDays) * 100;
                    
                    return {
                        left: `${Math.max(0, Math.min(100, leftPercent))}%`,
                        width: `${Math.max(2, Math.min(100, widthPercent))}%`
                    };
                },
                
                getBarClass(milestone) {
                    const status = Number(milestone.ms_status);
                    if (status === 0) return 'not-started';
                    if (status === 1) return 'in-progress';
                    if (status === 2) return 'rejected';
                    if (status === 3) return 'completed';
                    if (status === 4) return 'review';
                    return 'not-started';
                },
                
                getStatusBadgeClass(status) {
                    const s = Number(status);
                    if (s === 0) return 'badge-not-started';
                    if (s === 1) return 'badge-in-progress';
                    if (s === 2) return 'badge-rejected';
                    if (s === 3) return 'badge-completed';
                    if (s === 4) return 'badge-review';
                    return '';
                },
                
                getPriorityBadgeClass(priority) {
                    const p = Number(priority);
                    if (p === 0) return 'badge-priority-normal';
                    if (p === 1) return 'badge-priority-important';
                    if (p === 2) return 'badge-priority-urgent';
                    if (p === 3) return 'badge-priority-super-urgent';
                    return 'badge-priority-normal';
                },
                
                getStatusClass(status) {
                    const s = Number(status);
                    if (s === 0) return 'not-started';
                    if (s === 1) return 'in-progress';
                    if (s === 2) return 'rejected';
                    if (s === 3) return 'completed';
                    if (s === 4) return 'review';
                    return '';
                },
                
                getStatusText(status) {
                    const s = Number(status);
                    if (s === 0) return '還未開始';
                    if (s === 1) return '進行中';
                    if (s === 2) return '退回';
                    if (s === 3) return '已完成';
                    if (s === 4) return '待審核';
                    return '未知';
                },
                
                getPriorityClass(priority) {
                    const p = Number(priority);
                    if (p === 0) return 'normal';
                    if (p === 1) return 'important';
                    if (p === 2) return 'urgent';
                    if (p === 3) return 'super-urgent';
                    return 'normal';
                },
                
                getPriorityText(priority) {
                    const p = Number(priority);
                    if (p === 0) return '一般';
                    if (p === 1) return '重要';
                    if (p === 2) return '緊急';
                    if (p === 3) return '超級緊急';
                    return '一般';
                },
                
                formatDateShort(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    return `${date.getMonth() + 1}/${date.getDate()}`;
                },
                
                formatTimeOnly(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    const seconds = String(date.getSeconds()).padStart(2, '0');
                    return `${hours}:${minutes}:${seconds}`;
                },
                
                formatDuration(startDate, endDate, milestone) {
                    if (!startDate) return '';
                    
                    // 從開始時間的 0 秒開始計算（將秒數和毫秒歸零）
                    const start = new Date(startDate);
                    start.setSeconds(0, 0);
                    
                    // 確定結束時間
                    const now = new Date();
                    let end = null;
                    let isStopped = false;
                    
                    // 檢查里程碑狀態：只有狀態為 3（已完成）時才停止計算
                    const status = milestone ? Number(milestone.ms_status) : null;
                    
                    if (status === 3) {
                        // 已完成：使用審核時間或完成時間作為結束時間
                        if (milestone.ms_approved_d) {
                            end = new Date(milestone.ms_approved_d);
                            isStopped = true;
                        } else if (milestone.ms_completed_d) {
                            end = new Date(milestone.ms_completed_d);
                            isStopped = true;
                        } else {
                            // 如果沒有審核時間或完成時間，使用截止時間或現在時間
                            if (endDate && new Date(endDate) < now) {
                                end = new Date(endDate);
                                isStopped = true;
                            } else {
                                end = now;
                            }
                        }
                    } else {
                        // 其他狀態（待審核、進行中等）：繼續使用當前時間計算
                        end = now;
                    }
                    
                    // 如果已停止計算，將秒數歸零；否則保留秒數以便實時更新
                    if (isStopped) {
                        end.setSeconds(0, 0);
                    }
                    
                    // 計算時間差（毫秒）
                    const diffMs = end - start;
                    if (diffMs < 0) return '00:00:00';
                    
                    // 計算總秒數
                    const totalSeconds = Math.floor(diffMs / 1000);
                    
                    // 計算小時、分鐘、秒
                    const hours = Math.floor(totalSeconds / 3600);
                    const minutes = Math.floor((totalSeconds % 3600) / 60);
                    const seconds = totalSeconds % 60;
                    
                    // 格式：HH:MM:SS（小時可以超過 24，例如 264:00:00 表示 264 小時）
                    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                },
                
                getBarTooltip(milestone) {
                    let tooltip = '';
                    
                    // 顯示進行時間
                    if (milestone.ms_start_d) {
                        const status = Number(milestone.ms_status);
                        const duration = this.formatDuration(milestone.ms_start_d, milestone.ms_end_d, milestone);
                        
                        if (duration) {
                            if (status === 3) {
                                // 已完成：顯示從開始到審核通過的時間
                                tooltip = `進行時間：${duration}（已完成）`;
                            } else if (status === 4) {
                                // 待審核：顯示從開始到現在的時間（持續計算）
                                tooltip = `進行時間：${duration}（待審核）`;
                            } else if (milestone.ms_end_d && new Date(milestone.ms_end_d) < new Date()) {
                                // 已經截止但未完成：顯示從開始到截止的時間
                                tooltip = `進行時間：${duration}（已截止）`;
                            } else {
                                // 進行中：顯示從開始到現在的時間（持續計算）
                                tooltip = `進行時間：${duration}（進行中）`;
                            }
                        }
                    } else {
                        tooltip = '進行時間：未開始';
                    }
                    
                    return tooltip;
                },
                
                showTooltip(event, milestone) {
                    // 移除舊的 tooltip
                    this.hideTooltip();
                    
                    // 保存當前里程碑和目標元素
                    this.currentTooltipMilestone = milestone;
                    this.tooltipTargetElement = event.currentTarget;
                    
                    // 創建 tooltip 元素
                    const tooltip = document.createElement('div');
                    tooltip.className = 'gantt-custom-tooltip';
                    tooltip.style.cssText = `
                        position: fixed;
                        background: rgba(0, 0, 0, 0.95);
                        color: white;
                        padding: 0.5rem 0.75rem;
                        border-radius: 6px;
                        font-size: 0.8rem;
                        white-space: pre-line;
                        z-index: 9999;
                        pointer-events: none;
                        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
                        line-height: 1.5;
                        max-width: 250px;
                        word-wrap: break-word;
                    `;
                    
                    document.body.appendChild(tooltip);
                    this.tooltipElement = tooltip;
                    
                    // 更新 tooltip 內容和位置
                    this.updateTooltipContent();
                    
                    // 每秒更新一次時間
                    this.tooltipTimer = setInterval(() => {
                        if (this.tooltipElement && this.currentTooltipMilestone) {
                            this.updateTooltipContent();
                        }
                    }, 1000);
                },
                
                updateTooltipContent() {
                    if (!this.tooltipElement || !this.currentTooltipMilestone) return;
                    
                    const milestone = this.currentTooltipMilestone;
                    const tooltipText = this.getBarTooltip(milestone);
                    
                    if (!tooltipText) return;
                    
                    // 更新內容
                    this.tooltipElement.textContent = tooltipText;
                    
                    // 重新計算位置（因為內容可能改變導致高度變化）
                    if (this.tooltipTargetElement) {
                        const rect = this.tooltipTargetElement.getBoundingClientRect();
                        const tooltipRect = this.tooltipElement.getBoundingClientRect();
                        
                        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
                        let top = rect.top - tooltipRect.height - 8;
                        
                        // 確保不會超出視窗
                        if (left < 10) left = 10;
                        if (left + tooltipRect.width > window.innerWidth - 10) {
                            left = window.innerWidth - tooltipRect.width - 10;
                        }
                        if (top < 10) {
                            top = rect.bottom + 8;
                        }
                        
                        this.tooltipElement.style.left = left + 'px';
                        this.tooltipElement.style.top = top + 'px';
                    }
                },
                
                hideTooltip() {
                    // 清除定時器
                    if (this.tooltipTimer) {
                        clearInterval(this.tooltipTimer);
                        this.tooltipTimer = null;
                    }
                    
                    // 移除 tooltip 元素
                    if (this.tooltipElement) {
                        this.tooltipElement.remove();
                        this.tooltipElement = null;
                    }
                    
                    // 清除當前里程碑和目標元素
                    this.currentTooltipMilestone = null;
                    this.tooltipTargetElement = null;
                },
                
                goBack() {
                    if (this.role_ID === 6) {
                        window.location.hash = '#pages/student_milestone.php';
                    } else {
                        window.location.hash = '#pages/milestone.php';
                    }
                },
                
                showMilestoneDetail(milestone) {
                    this.selectedMilestone = milestone;
                },
                
                closeMilestoneDetail() {
                    this.selectedMilestone = null;
                },
                
                formatDate(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    return `${year}/${month}/${day}`;
                },
                
                formatDateTime(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    const hours = date.getHours();
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    const period = hours >= 12 ? '下午' : '上午';
                    const displayHours = hours > 12 ? hours - 12 : (hours === 0 ? 12 : hours);
                    return `${year}/${month}/${day} ${period}${String(displayHours).padStart(2, '0')}:${minutes}`;
                },
                
                getStatusBadgeClass(status) {
                    const s = Number(status);
                    if (s === 0) return 'badge-not-started';
                    if (s === 1) return 'badge-in-progress';
                    if (s === 2) return 'badge-rejected';
                    if (s === 3) return 'badge-completed';
                    if (s === 4) return 'badge-review';
                    return '';
                },
                
                getPriorityBadgeClass(priority) {
                    const p = Number(priority);
                    if (p === 0) return 'badge-priority-normal';
                    if (p === 1) return 'badge-priority-important';
                    if (p === 2) return 'badge-priority-urgent';
                    if (p === 3) return 'badge-priority-super-urgent';
                    return 'badge-priority-normal';
                },
                
                editMilestone(milestone) {
                    // 跳轉到編輯頁面或打開編輯 modal
                    if (this.role_ID === 4) {
                        window.location.hash = '#pages/milestone.php';
                    }
                },
                
                deleteMilestone(milestone) {
                    // 刪除里程碑的邏輯
                    if (confirm('確定要刪除這個里程碑嗎？')) {
                        // 調用刪除 API
                        console.log('刪除里程碑:', milestone.ms_ID);
                    }
                }
            }
        });

        ganttApp.mount('#ganttApp');
        window.ganttApp = ganttApp;
    }
}

