// 里程碑頁面 Vue.js 應用
// 使用命名空間避免重複執行
if (!window._milestoneAppInitialized) {
    window._milestoneAppInitialized = true;
    
    // 清理函數
    function cleanupMilestoneApp() {
        if (window.milestoneApp && typeof window.milestoneApp.unmount === 'function') {
            try {
                window.milestoneApp.unmount();
                window.milestoneApp = null;
            } catch (e) {
                console.warn('卸載 milestone app 時出錯:', e);
            }
        }
        // 重置標記，允許重新初始化
        window._milestoneAppInitialized = false;
    }

    // 如果已經存在 app，先卸載
    cleanupMilestoneApp();

    // 監聽頁面切換事件，自動清理
    // 移除舊的監聽器（如果存在），避免重複監聽
    const oldHandler = window._milestoneCleanupHandler;
    if (oldHandler) {
        window.removeEventListener('pageBeforeUnload', oldHandler);
    }
    window._milestoneCleanupHandler = cleanupMilestoneApp;
    window.addEventListener('pageBeforeUnload', cleanupMilestoneApp);

    // 檢查目標元素是否存在
    const mountEl = document.querySelector('#milestoneApp');
    if (!mountEl) {
        console.warn('找不到 #milestoneApp 元素');
        window._milestoneAppInitialized = false; // 重置標記以便下次重試
    } else {
        const { createApp } = Vue;

        const milestoneApp = createApp({
            data() {
                return {
                    milestones: [],
                    requirements: [],
                    teams: [],
                    filters: {
                        team_ID: 0,
                        req_ID: 0,
                        status: -1
                    },
                    selectedMilestone: null,
                    showCreateModal: false,
                    showEditModal: false,
                    timeError: '',
                    form: {
                        ms_ID: 0,
                        req_ID: 0,
                        team_ID: 0,
                        ms_title: '',
                        ms_desc: '',
                        ms_start_d: '',
                        ms_end_d: '',
                        ms_status: 0,
                        ms_priority: 0
                    }
                };
            },
            computed: {
                filteredMilestones() {
                    let filtered = this.milestones;
                    
                    if (this.filters.team_ID > 0) {
                        filtered = filtered.filter(m => m.team_ID == this.filters.team_ID);
                    }
                    
                    if (this.filters.req_ID > 0) {
                        filtered = filtered.filter(m => m.req_ID == this.filters.req_ID);
                    }
                    
                    if (this.filters.status >= 0) {
                        filtered = filtered.filter(m => m.ms_status == this.filters.status);
                    }

                    const copy = [...filtered];
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const todayMs = today.getTime();

                    const diffFromToday = (end, start) => {
                        const d = end || start;
                        if (!d) return Number.MAX_SAFE_INTEGER;
                        const t = Date.parse(d);
                        if (Number.isNaN(t)) return Number.MAX_SAFE_INTEGER;
                        return Math.abs(t - todayMs);
                    };

                    const statusRank = (s) => {
                        // 0/1:進行中,2:退回,3:已完成,4:待審核
                        if (s === 0 || s === 1 || s === 2) return 0;
                        if (s === 4) return 1;
                        if (s === 3) return 2;
                        return 3;
                    };

                    return copy.sort((a, b) => {
                        const ra = statusRank(Number(a.ms_status));
                        const rb = statusRank(Number(b.ms_status));
                        if (ra !== rb) return ra - rb;

                        const pa = Number(a.ms_priority ?? 0);
                        const pb = Number(b.ms_priority ?? 0);
                        if (pa === 3 && pb !== 3) return -1;
                        if (pb === 3 && pa !== 3) return 1;
                        if (pb !== pa) return pb - pa;

                        const da = diffFromToday(a.ms_end_d, a.ms_start_d);
                        const db = diffFromToday(b.ms_end_d, b.ms_start_d);
                        if (da !== db) return da - db;

                        return Number(b.ms_ID || 0) - Number(a.ms_ID || 0);
                    });
                }
            },
            mounted() {
                this.loadRequirements();
                this.loadTeams();
                this.loadMilestones();
            },
            methods: {
                // 載入基本需求列表
                async loadRequirements() {
                    try {
                        const response = await fetch('api.php?do=get_requirements');
                        if (!response.ok) {
                            // 讀取後端錯誤訊息，方便除錯
                            const errorText = await response.text();
                            console.error('get_requirements 錯誤回應:', errorText);
                            let msg = '載入失敗';
                            try {
                                const errJson = JSON.parse(errorText);
                                msg = errJson.message || errJson.msg || msg;
                            } catch (e) {
                                if (errorText) msg = errorText;
                            }
                            throw new Error(msg);
                        }
                        this.requirements = await response.json();
                    } catch (error) {
                        console.error('載入基本需求失敗:', error);
                        Swal.fire('錯誤', error.message || '載入基本需求失敗', 'error');
                    }
                },

                // 載入團隊列表
                async loadTeams() {
                    try {
                        const response = await fetch('api.php?do=get_teams');
                        if (!response.ok) throw new Error('載入失敗');
                        this.teams = await response.json();
                    } catch (error) {
                        console.error('載入團隊失敗:', error);
                        Swal.fire('錯誤', '載入團隊失敗', 'error');
                    }
                },

                // 載入里程碑列表
                async loadMilestones() {
                    try {
                        let url = 'api.php?do=get_milestones';
                        const params = [];
                        
                        if (this.filters.team_ID > 0) {
                            params.push(`team_ID=${this.filters.team_ID}`);
                        }
                        if (this.filters.req_ID > 0) {
                            params.push(`req_ID=${this.filters.req_ID}`);
                        }
                        
                        if (params.length > 0) {
                            url += '&' + params.join('&');
                        }
                        
                        const response = await fetch(url);
                        if (!response.ok) throw new Error('載入失敗');
                        const data = await response.json();
                        this.milestones = Array.isArray(data) ? data : [];
                        
                        // 調試：檢查狀態為4的里程碑
                        const pending = this.milestones.filter(m => Number(m.ms_status) === 4);
                        if (pending.length > 0) {
                            console.log('待審核里程碑:', pending);
                        }
                    } catch (error) {
                        console.error('載入里程碑失敗:', error);
                        Swal.fire('錯誤', '載入里程碑失敗', 'error');
                    }
                },

                // 選擇里程碑
                selectMilestone(milestone) {
                    // 可以添加點擊卡片後的詳細視圖邏輯
                    console.log('選擇里程碑:', milestone);
                },
                
                showMilestoneDetail(milestone) {
                    this.selectedMilestone = milestone;
                },
                
                closeMilestoneDetail() {
                    this.selectedMilestone = null;
                },
                
                getStatusBarClass(status) {
                    const s = Number(status);
                    if (s === 0) return 'bar-not-started';
                    if (s === 1) return 'bar-in-progress';
                    if (s === 2) return 'bar-rejected';
                    if (s === 3) return 'bar-completed';
                    if (s === 4) return 'bar-review';
                    return 'bar-not-started';
                },

                // 編輯里程碑
                editMilestone(milestone) {
                    this.form = {
                        ms_ID: milestone.ms_ID,
                        req_ID: milestone.req_ID || 0,
                        team_ID: milestone.team_ID,
                        ms_title: milestone.ms_title,
                        ms_desc: milestone.ms_desc || '',
                        ms_start_d: this.formatDateTimeLocal(milestone.ms_start_d),
                        ms_end_d: this.formatDateTimeLocal(milestone.ms_end_d),
                        ms_status: milestone.ms_status,
                        ms_priority: milestone.ms_priority || 0
                    };
                    this.showEditModal = true;
                },

                // 刪除里程碑
                async deleteMilestone(milestone) {
                    const result = await Swal.fire({
                        title: '確認刪除',
                        text: `確定要刪除「${milestone.ms_title}」嗎？`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: '確定刪除',
                        cancelButtonText: '取消'
                    });

                    if (!result.isConfirmed) return;

                    try {
                        const formData = new FormData();
                        formData.append('ms_ID', milestone.ms_ID);

                        const response = await fetch('api.php?do=delete_milestone', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (!data.ok) throw new Error(data.msg || '刪除失敗');

                        Swal.fire('成功', '里程碑已刪除', 'success');
                        this.loadMilestones();
                    } catch (error) {
                        console.error('刪除失敗:', error);
                        Swal.fire('錯誤', error.message || '刪除失敗', 'error');
                    }
                },

                // 審核里程碑
                async approveMilestone(milestone, action) {
                    const actionText = action === 'approve' ? '完成' : '退回';
                    const result = await Swal.fire({
                        title: '確認操作',
                        text: `確定要${actionText}「${milestone.ms_title}」嗎？`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: action === 'approve' ? '#10b981' : '#ef4444',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: '確定',
                        cancelButtonText: '取消',
                        reverseButtons: true
                    });

                    if (!result.isConfirmed) return;

                    try {
                        const formData = new FormData();
                        formData.append('ms_ID', milestone.ms_ID);
                        formData.append('action', action);

                        const response = await fetch('api.php?do=approve_milestone', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (!data.ok) throw new Error(data.msg || '操作失敗');

                        Swal.fire({
                            icon: 'success',
                            title: '成功',
                            text: `里程碑已${actionText}`,
                            confirmButtonText: '確定',
                            reverseButtons: true
                        });
                        
                        // 重新載入里程碑列表
                        await this.loadMilestones();
                        
                        // 更新通知數量（如果有的話）
                        if (typeof updateNotificationCount === 'function') {
                            updateNotificationCount();
                        }
                    } catch (error) {
                        console.error('操作失敗:', error);
                        Swal.fire({
                            icon: 'error',
                            title: '錯誤',
                            text: error.message || '操作失敗',
                            confirmButtonText: '確定',
                            reverseButtons: true
                        });
                    }
                },

                // 儲存里程碑
                async saveMilestone() {
                    try {
                        // 驗證時間：截止時間不可小於開始時間
                        this.validateTimeRange();
                        if (this.timeError) {
                            Swal.fire('錯誤', this.timeError, 'error');
                            return;
                        }

                        const formData = new FormData();
                        Object.keys(this.form).forEach(key => {
                            if (this.form[key] !== null && this.form[key] !== undefined) {
                                formData.append(key, this.form[key]);
                            }
                        });

                        const action = this.showEditModal ? 'update_milestone' : 'create_milestone';
                        const response = await fetch(`api.php?do=${action}`, {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (!data.ok) throw new Error(data.msg || '儲存失敗');

                        Swal.fire('成功', this.showEditModal ? '里程碑已更新' : '里程碑已建立', 'success');
                        this.closeModal();
                        this.loadMilestones();
                    } catch (error) {
                        console.error('儲存失敗:', error);
                        Swal.fire('錯誤', error.message || '儲存失敗', 'error');
                    }
                },

                // 顯示甘特圖
                showGanttChart() {
                    window.location.hash = '#pages/gantt_chart.php';
                },
                
                // 驗證時間範圍
                validateTimeRange() {
                    this.timeError = '';
                    if (this.form.ms_start_d && this.form.ms_end_d) {
                        const startTime = new Date(this.form.ms_start_d).getTime();
                        const endTime = new Date(this.form.ms_end_d).getTime();
                        
                        if (endTime < startTime) {
                            this.timeError = '截止時間不可小於開始時間';
                        }
                    }
                },
                
                // 關閉 Modal
                closeModal() {
                    this.showCreateModal = false;
                    this.showEditModal = false;
                    this.timeError = '';
                    this.form = {
                        ms_ID: 0,
                        req_ID: 0,
                        team_ID: 0,
                        ms_title: '',
                        ms_desc: '',
                        ms_start_d: '',
                        ms_end_d: '',
                        ms_status: 0,
                        ms_priority: 0
                    };
                },

                // 狀態相關方法
                getStatusClass(status) {
                    if (status === 0) return 'status-not-started';
                    if (status === 1) return 'status-in-progress';
                    if (status === 2) return 'status-rejected';
                    if (status === 3) return 'status-completed';
                    if (status === 4) return 'status-review';
                    return '';
                },

                getStatusBadgeClass(status) {
                    if (status === 0) return 'not-started';
                    if (status === 1) return 'in-progress';
                    if (status === 2) return 'rejected';
                    if (status === 3) return 'completed';
                    if (status === 4) return 'review';
                    return '';
                },

                getStatusIcon(status) {
                    if (status === 0) return 'fa-solid fa-clock';
                    if (status === 1) return 'fa-solid fa-play-circle';
                    if (status === 2) return 'fa-solid fa-rotate-left';
                    if (status === 3) return 'fa-solid fa-check-circle';
                    if (status === 4) return 'fa-solid fa-hourglass-half';
                    return 'fa-solid fa-question';
                },

                getStatusText(status) {
                    if (status === 0) return '還未開始';
                    if (status === 1) return '進行中';
                    if (status === 2) return '退回';
                    if (status === 3) return '已完成';
                    if (status === 4) return '待審核';
                    return '未知狀態';
                },

                // 優先級相關方法
                getPriorityClass(priority) {
                    if (priority === 0) return 'priority-normal';
                    if (priority === 1) return 'priority-important';
                    if (priority === 2) return 'priority-urgent';
                    if (priority === 3) return 'priority-super-urgent';
                    return 'priority-normal';
                },

                getPriorityText(priority) {
                    if (priority === 0) return '一般';
                    if (priority === 1) return '重要';
                    if (priority === 2) return '緊急';
                    if (priority === 3) return '超級緊急';
                    return '一般';
                },

                // 日期格式化
                formatDate(dateString) {
                    if (!dateString) return '-';
                    const date = new Date(dateString);
                    return date.toLocaleDateString('zh-TW', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit'
                    });
                },

                formatDateTime(dateString) {
                    if (!dateString) return '-';
                    const date = new Date(dateString);
                    return date.toLocaleString('zh-TW', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                },

                formatDateTimeLocal(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    return `${year}-${month}-${day}T${hours}:${minutes}`;
                }
            }
        });

        // 掛載 app
        milestoneApp.mount('#milestoneApp');
        // 將 app 實例保存到全域，方便卸載
        window.milestoneApp = milestoneApp;
    }
}

