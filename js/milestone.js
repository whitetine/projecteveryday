// 里程碑頁面 Vue.js 應用
const { createApp } = Vue;

createApp({
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
            showCreateModal: false,
            showEditModal: false,
            form: {
                ms_ID: 0,
                req_ID: 0,
                team_ID: 0,
                ms_title: '',
                ms_desc: '',
                ms_start_d: '',
                ms_end_d: '',
                ms_status: 0
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
            
            return filtered;
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
                if (!response.ok) throw new Error('載入失敗');
                this.requirements = await response.json();
            } catch (error) {
                console.error('載入基本需求失敗:', error);
                Swal.fire('錯誤', '載入基本需求失敗', 'error');
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
                this.milestones = await response.json();
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

        // 編輯里程碑
        editMilestone(milestone) {
            this.form = {
                ms_ID: milestone.ms_ID,
                req_ID: milestone.req_ID,
                team_ID: milestone.team_ID,
                ms_title: milestone.ms_title,
                ms_desc: milestone.ms_desc || '',
                ms_start_d: this.formatDateTimeLocal(milestone.ms_start_d),
                ms_end_d: this.formatDateTimeLocal(milestone.ms_end_d),
                ms_status: milestone.ms_status
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
            const actionText = action === 'complete' ? '標記為完成' : '審核通過';
            const result = await Swal.fire({
                title: '確認操作',
                text: `確定要${actionText}「${milestone.ms_title}」嗎？`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: '確定',
                cancelButtonText: '取消'
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

                Swal.fire('成功', `里程碑已${actionText}`, 'success');
                this.loadMilestones();
            } catch (error) {
                console.error('操作失敗:', error);
                Swal.fire('錯誤', error.message || '操作失敗', 'error');
            }
        },

        // 儲存里程碑
        async saveMilestone() {
            try {
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

        // 關閉 Modal
        closeModal() {
            this.showCreateModal = false;
            this.showEditModal = false;
            this.form = {
                ms_ID: 0,
                req_ID: 0,
                team_ID: 0,
                ms_title: '',
                ms_desc: '',
                ms_start_d: '',
                ms_end_d: '',
                ms_status: 0
            };
        },

        // 狀態相關方法
        getStatusClass(status) {
            if (status === 0) return 'status-pending';
            if (status === 1) return 'status-completed';
            if (status === 2) return 'status-approved';
            return '';
        },

        getStatusBadgeClass(status) {
            if (status === 0) return 'pending';
            if (status === 1) return 'completed';
            if (status === 2) return 'approved';
            return '';
        },

        getStatusIcon(status) {
            if (status === 0) return 'fa-solid fa-clock';
            if (status === 1) return 'fa-solid fa-check-circle';
            if (status === 2) return 'fa-solid fa-stamp';
            return 'fa-solid fa-question';
        },

        getStatusText(status) {
            if (status === 0) return '進行中';
            if (status === 1) return '已完成';
            if (status === 2) return '已通過';
            return '未知';
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
}).mount('#milestoneApp');

