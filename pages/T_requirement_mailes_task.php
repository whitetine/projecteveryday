<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
<link rel="stylesheet" href="css/group_manage.css?v=<?= time() ?>">
<link rel="stylesheet" href="css/task.css?v=<?= time() ?>">
<link rel="stylesheet" href="css/milestone.css?v=<?= time() ?>" onload="this.onload=null;this.rel='stylesheet'">
<link rel="stylesheet" href="css/gantt_chart.css?v=<?= time() ?>" onload="this.onload=null;this.rel='stylesheet'">
<noscript>
    <link rel="stylesheet" href="css/milestone.css?v=<?= time() ?>">
</noscript>
<style>
    /* 讓 modal 永遠在 backdrop 前面 */
    .modal {
        z-index: 2000 !important;
    }

    /* 讓黑底永遠在 modal 後面 */
    .modal-backdrop {
        z-index: 1040 !important;
    }

    .swal2-container {
        z-index: 999999 !important;
    }
</style>
<?php session_start(); ?>
<div id="task_app">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fa-solid fa-bullseye me-2" style="color: #ffc107;"></i>待辦事項
        </h1>
    </div>
    <!-- ⭐ 懸浮 Tabs：置中 -->
    <div class="team-switch-container">
        <div class="team-switch-title">
            <i class="fa-solid fa-people-group"></i> 選擇專題小組
        </div>
        <div class="team-switch-buttons">
            <button
                class="team-btn"
                :class="{ active: now_team_ID === i.team_ID }"
                v-for="i in all_team_ID"
                @click="changeTab(activeTab, i.team_ID)">
                <i class="fa-solid fa-users"></i>
                {{ i.team_project_name }}
            </button>
        </div>
    </div>
    <!-- 篩選區域 -->
    <div class="filter-section">
        <div class="filter-card">
            <div class="filter-header">
                <h3 style="font-weight:800">
                    分列方式
                </h3>
                <button class="btn-gantt" @click="task_modal_show()">
                    新增待辦事項
                </button>
            </div>
            <div class="filter-controls">
                <button class="req-filter-btn" :class="{ active: filter.task_filter === 'status' }"
                    @click="filter.task_filter = 'status'">狀態</button>

                <button class="req-filter-btn" :class="{ active: filter.task_filter === '' }"
                    @click="filter.task_filter = ''">標籤</button>

                <button class="req-filter-btn" :class="{ active: filter.task_filter === 'people' }"
                    @click="filter.task_filter = 'people'">組員</button>
            </div>
        </div>
    </div>

    <!-- 專題需求牆待辦事項：統一 board -->
    <div class="todo-board">
        <div class="todo-column" v-for="col in activeColumns" :key="col.key">
            <div class="todo-column-header">
                <h3 class="todo-column-title">{{ col.label }}</h3>
                <span class="todo-column-count">{{ col.tasks.length }}</span>
            </div>

            <div class="todo-column-body">
                <div class="todo-note"
                    v-for="task in col.tasks"
                    :key="task.task_ID"
                    @click="now_task_click(task)"
                    :style="{ background: priorityNoteColor(task.task_priority) }">

                    <div class="todo-note-pin"></div>

                    <div class="todo-note-title">
                        {{ task.task_title }}
                    </div>

                    <div class="todo-note-desc">
                        {{ task.task_desc }}
                    </div>

                    <div class="todo-note-footer">
                        <span class="todo-note-tag"
                            v-if="task.task_priority"
                            :style="priorityTagStyle(task.task_priority)">
                            # {{ priorityText(task.task_priority) }}
                        </span>

                        

                        <span class="req-count-tag"
                            :style="task.task_status === 1 ? 'background:#F8BF63' : (task.task_status === 3 ? 'background:#CAFCBB' : '')">
                            #&ensp;{{ statusChipText(task) }}
                        </span>
                    </div>
                </div>

                <div class="todo-empty" v-if="!col.tasks.length">
                    尚未建立待辦事項～
                </div>
            </div>
        </div>
    </div>

    <teleport to="body">
        <!-- 新增 / 編輯待辦事項 -->
        <div class="modal fade" id="task_modal" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="mb-0">{{ form.id ? '編輯待辦事項' : '新增待辦事項' }}</h2>
                        <button @click="task_modal_close"
                            style="background:none;border:none;font-size:28px;cursor:pointer;color:#999;line-height:1;"
                            class="ms-auto">&times;</button>
                    </div>

                    <div class="modal-body">
                        <div class="task-modal-grid">
                            <!-- 左邊表單 -->
                            <div class="task-form">
                                <table class="w-100">
                                    <tr>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text"><b>對應預期成果</b></span>
                                                <select class="form-select" v-model="form.req_ID">
                                                    <option :value="null">不連結</option>
                                                    <option v-for="i in all_exresultdata" :key="i.rd_ID" :value="i.rd_ID">
                                                        {{ i.rd_title }}
                                                    </option>
                                                </select>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text"><b>待辦事項標題</b></span>
                                                <input type="text" v-model="form.title" class="form-control" maxlength="18">
                                            </div>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <div class="input-group range-group">
                                                <span class="input-group-text"><b>誰的待辦事項</b></span>
                                                <select class="form-select" v-model="form.who_task">
                                                    <option :value="null">暫不指派</option>
                                                    <option
                                                        v-for="i in now_teammumber.filter(c => Number(c.role_ID) === 6)"
                                                        :key="i.team_u_ID"
                                                        :value="i.team_u_ID">
                                                        {{ i.u_name }}
                                                    </option>
                                                </select>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <center><span style="color:gray">以下資料非必填</span></center>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text"><b>待辦事項說明</b></span>
                                                <textarea class="form-control" rows="4" style="resize:none;" v-model="form.desc"></textarea>
                                            </div>
                                        </td>
                                    </tr>

                                    
                                  
                                </table>
                            </div>

                            <!-- 右邊預覽 -->
                            <div class="task-preview">
                                <div class="task-preview-header">
                                    <div class="task-preview-title">預覽</div>
                                </div>

                                <div class="todo-note preview-note" :style="{ background: priorityNoteColor(form.priority) }">
                                    <div class="todo-note-pin"></div>

                                    <div class="todo-note-title">
                                        {{ form.title?.trim() ? form.title : '（尚未填寫標題）' }}
                                    </div>

                                    <div class="todo-note-desc">
                                        {{ form.desc?.trim() ? form.desc : '（尚未填寫說明）' }}
                                    </div>

                                    <div class="todo-note-footer">
                                        <span class="todo-note-tag"
                                            v-if="form.priority"
                                            :style="priorityTagStyle(form.priority)">
                                            # {{ priorityText(form.priority) }}
                                        </span>

                                        

                                        <span class="req-count-tag"
                                            :style="form.who_task ? 'background:#F8BF63' : ''">
                                            #&ensp;{{ form.who_task ? now_teammumber.find(x => x.team_u_ID === form.who_task)?.u_name + '　進行中' : '未署名' }}
                                        </span>
                                    </div>
                                </div>

                                <div class="task-preview-meta">
                                    <div class="meta-row">
                                        <span class="meta-label">對應預期成果：</span>
                                        <span class="meta-text">
                                            {{ all_exresultdata.find(x => x.rd_ID === form.req_ID)?.rd_title || '未指定' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-danger" @click="task_submit('del')" v-if="form.id" style="margin-right:14px;">刪除</button>
                        <button class="btn btn-primary" @click="task_submit('edit')" v-if="form.id">送出編輯</button>
                        <button class="btn btn-primary" @click="task_submit('new')" v-else>確定新增</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 查看待辦事項 -->
        <div class="modal fade" id="task_look_modal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>待辦事項詳細資料</h2>
                        <button data-bs-dismiss="modal"
                            style="background:none;border:none;font-size:28px;cursor:pointer;color:#999;line-height:1;"
                            class="ms-auto">&times;</button>
                    </div>

                    <div class="modal-body req-detail-card">
                        <div class="req-detail-header">
                            <h3 class="req-detail-title">{{ now_task.task_title }}</h3>

                            <span class="req-count-tag" v-if="now_task.task_status==0">
                                未署名
                            </span>
                            <span class="req-count-tag" v-if="now_task.task_status==1" style="background:#F8BF63">
                                進行中
                            </span>
                            <span class="req-count-tag" v-if="now_task.task_status==3" style="background:#CAFCBB">
                                已完成
                            </span>
                        </div>

                        <div class="req-detail-section">
                            <label class="req-detail-label">說明：</label>
                            <p class="req-detail-text">
                                {{ now_task.task_desc || '（尚未填寫說明）' }}
                            </p>
                        </div>

                        <div class="req-detail-section">
                            <label class="req-detail-label">負責人：</label>
                            <p class="req-detail-text">
                                <span v-if="now_task.task_status==0">
                                    尚未有人接下這個待辦事項
                                </span>
                                <span v-else>
                                    {{ now_task.done_name + (now_task.task_done_d ? '　' + now_task.task_done_d : '') + (now_task.task_status==1 ? '　接下待辦事項' : '　完成待辦事項') }}
                                </span>
                            </p>
                        </div>

                       

                        <div class="req-detail-section">
                            <label class="req-detail-label">標籤：</label>
                            <p class="req-detail-text">
                                <span class="req-count-tag"
                                    v-if="now_task.task_priority"
                                    :style="priorityTagStyle(now_task.task_priority)">
                                    # {{ priorityText(now_task.task_priority) }}
                                </span>
                                <span v-else>尚未設定標籤</span>
                            </p>
                        </div>

                        <div class="req-detail-section">
                            <p class="req-detail-text">
                                創立者：{{ now_task.creator_name }}<br>
                                創立時間：{{ now_task.task_created_d }}
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer" v-if="now_task.task_status!==3">
                        <button class="btn btn-secondary"
                            v-if="u_ID==now_task.task_u_ID && now_task.task_status==0"
                            @click="task_modal_show('edit')"
                            style="margin-right: 14px;">
                            編輯
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</div>

<!-- 🔹 下方分頁內容 -->
<div class="mt-2">
    <!-- 待辦任務公佈欄section -->
    <section v-show="activeTab === 'task'">
        <!-- 這裡匯入的「待辦任務公佈欄心願待辦事項」畫面 -->
        <?php include "T_include_task.php" ?>
    </section>
</div>
</div>
<script>
    function toast({
        type = 'info',
        title = '',
        text = '',
        ms = 3000
    } = {}) {
        Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: type,
            title: title,
            html: text ? `<small>${text}</small>` : '',
            timer: ms,
            timerProgressBar: true,
            showConfirmButton: false,
            allowEscapeKey: false,
            allowOutsideClick: false,
            customClass: {
                popup: 'my-toast'
            }
        });
    }
    if (window.taskVueApp && typeof window.taskVueApp.unmount === 'function') {
        try {
            window.taskVueApp.unmount();
        } catch (e) {
            console.warn('卸載 task app 時出錯:', e);
        }
    }
    window.taskVueApp = null;

    if (!window.taskVueApp) {
        window.taskVueApp = Vue.createApp({
            data() {
                return {
                    // 分頁功能
                    activeTab: 'task',
                    tabs: [],
                    // 共用變數
                    u_ID: "<?= $_SESSION["u_ID"] ?>",
                    all_team_ID: [],
                    now_team_ID: null,
                    all_teammumber: [],
                    now_teammumber: [],
                    now_group: {
                        ID: "",
                        name: "",
                        team_project_name: ""
                    },
                    now_cohort_ID: null,

                    // task待辦任務公佈欄功能變數
                    all_task: [],
                    now_task: [],
                    filter: {
                        task_filter: "status",
                        task_filter_status: "",
                        requirement_status: ""
                    },
                    form: {
                        id: null,
                        req_ID: null,
                        title: null,
                        desc: null,
                        priority: 5,
                        who_task: null,
                      
                    },
                    all_exresultdata: [],
                    now_task: [],
                    // milestone任務公佈欄管理功能變數
                    milestoneList: [],
                    milestoneRequirements: [],
                    milestoneTeams: [],
                    milestoneFilters: {
                        team_ID: 0,
                        req_ID: 0,
                        status: -1,
                        priority: -1
                    },
                    selectedMilestone: null,
                    showCreateMilestoneModal: false,
                    showEditMilestoneModal: false,
                    milestoneTimeError: '',
                    showGanttView: false,
                    ganttTimeScale: [],
                    ganttStartDate: null,
                    ganttEndDate: null,
                    ganttTooltipElement: null,
                    ganttTooltipTimer: null,
                    ganttCurrentTooltipMilestone: null,
                    ganttTooltipTargetElement: null,
                    milestoneForm: {
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
                // ★ 依待辦任務公佈欄狀態分成三列（0 未署名、1 進行中、3 已完成）
                // ★ 依狀態分成三列（0 未署名、1 進行中、3 已完成）
                statusColumns() {
                    const base = [{
                            key: 0,
                            label: '未署名',
                            tasks: []
                        },
                        {
                            key: 1,
                            label: '進行中',
                            tasks: []
                        },
                        {
                            key: 3,
                            label: '已完成',
                            tasks: []
                        },
                    ];
                    this.filtered_task.forEach(t => {
                        const s = Number(t.task_status);
                        const col = base.find(c => c.key === s);
                        if (col) col.tasks.push(t);
                    });
                    return base;
                },
                // ★ 依重要程度分成四列
                todoColumns() {
                    const base = [{
                            key: 1,
                            label: '一般',
                            tasks: []
                        },
                        {
                            key: 2,
                            label: '重要',
                            tasks: []
                        },
                        {
                            key: 4,
                            label: '緊急',
                            tasks: []
                        },
                        {
                            key: 5,
                            label: '老師交辦',
                            tasks: []
                        },
                        {
                            key: 6,
                            label: '審查建議',
                            tasks: []
                        },
                    ];
                    this.filtered_task.forEach(t => {
                        const p = Number(t.task_priority) || 1;
                        const col = base.find(c => c.key === p) || base[0];
                        col.tasks.push(t);
                    });
                    return base;
                },
                // ★ 依每個組員分列
                peopleColumns() {
                    const cols = this.now_teammumber
                        .filter(m => Number(m.role_ID) === 6)
                        .map(m => ({
                            key: m.team_u_ID,
                            label: m.u_name,
                            tasks: []
                        }));

                    const colMap = {};
                    cols.forEach(c => {
                        colMap[c.key] = c;
                    });

                    this.filtered_task.forEach(t => {
                        let ownerId = t.task_u_ID;
                        if (t.task_done_u_ID && Number(t.task_status) !== 0) {
                            ownerId = t.task_done_u_ID;
                        }
                        const col = colMap[ownerId];
                        if (col) col.tasks.push(t);
                    });

                    return cols;
                },
                // ✅ 根據當前分列方式，回傳要給 board 用的 columns
                activeColumns() {
                    if (this.filter.task_filter === 'status') {
                        return this.statusColumns;
                    }
                    if (this.filter.task_filter === 'people') {
                        return this.peopleColumns;
                    }
                    // default：以重要程度
                    return this.todoColumns;
                },

                filtered_task() {
                    const mineFilter = this.filter.task_filter; // '' or 'mine'
                    const statusFilter = this.filter.task_filter_status; // '', 'notyet', 'taken', 'done'
                    const u_ID = this.u_ID;
                    return this.all_task.filter(item => {
                        // 1️⃣ 先處理「篩選：我的」
                        if (mineFilter === 'mine') {
                            const isCreator = item.task_u_ID === u_ID; // 我建立的待辦任務公佈欄
                            const isTaker = item.task_done_u_ID === u_ID; // 我接下的待辦任務公佈欄
                            if (!isCreator && !isTaker) return false;
                        }
                        // 2️⃣ 再處理狀態篩選
                        switch (statusFilter) {
                            case 'notyet': // 未屬名
                                return item.task_status === 0;
                            case 'taken': // 被接下
                                return item.task_status === 1;
                            case 'done': // 已完成
                                return item.task_status === 3;
                            default: // '' = ALL
                                return true;
                        }
                    });
                },
                // milestone任務公佈欄管理功能computed
                filteredMilestoneList() {
                    let filtered = this.milestoneList;

                    if (this.milestoneFilters.team_ID > 0) {
                        filtered = filtered.filter(m => m.team_ID == this.milestoneFilters.team_ID);
                    }

                    if (this.milestoneFilters.req_ID > 0) {
                        filtered = filtered.filter(m => m.req_ID == this.milestoneFilters.req_ID);
                    }

                    if (this.milestoneFilters.status >= 0) {
                        filtered = filtered.filter(m => m.ms_status == this.milestoneFilters.status);
                    }

                    if (this.milestoneFilters.priority >= 0) {
                        filtered = filtered.filter(m => (m.ms_priority || 0) == this.milestoneFilters.priority);
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
                },
            },
            methods: {
                // task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能task待辦任務公佈欄功能
                changeTab(key, team_ID) {
                    this.activeTab = key || this.activeTab;

                    if (team_ID) {
                        this.now_team_ID = team_ID;
                        this.now_teammumber = this.all_teammumber.filter(m => m.team_ID == team_ID);
                    }

                    if (this.activeTab === "task") {
                        this.get_task();
                        $.post("../modules/T_req&task.php?do=get_exresultdata", {
                            team_ID: this.now_team_ID
                        }, item => {
                            const data = JSON.parse(item) || {};
                            this.all_exresultdata = data.exresultdata || data || [];
                        });
                    }

                    if (this.activeTab === 'milestone') {
                        this.loadMilestoneRequirements();
                        this.loadMilestoneTeams();
                        this.loadMilestones();
                    }
                },
                priorityNoteColor(p) {
                    const pr = Number(p) || 1;
                    if (pr === 1) return '#FFF9C4'; // 一般：淡黃
                    if (pr === 2) return '#D1EAFE'; // 重要：淡藍（便條底色）
                    if (pr === 4) return '#ffd9ddff'; // 緊急：淡橘
                    if (pr === 5) return '#E9D5FF'; // 老師交辦：淡紫
                    if (pr === 6) return '#E5E7EB'; // 審查建議：淡灰
                    return '#FFD9DD'; // 其他：淡粉（保底）
                },
                priorityTagStyle(p) {
                    const pr = Number(p) || 1;
                    if (pr === 1) return 'background:#FFE98A;color:#7C5B00;';
                    if (pr === 2) return 'background:rgba(59,130,246,.15);color:#1D4ED8;'; // 重要：藍
                    if (pr === 4) return 'background:#FF955C;color:#7C1D10;'; // 緊急
                    if (pr === 5) return 'background:#C4B5FD;color:#4C1D95;'; // 老師交辦：紫
                    if (pr === 6) return 'background:#D1D5DB;color:#374151;'; // 審查建議：灰
                    return 'background:#FF6C6CC2;color:#7C1D10;';
                },
                priorityText(p) {
                    const pr = Number(p) || 1;
                    if (pr === 1) return '一般';
                    if (pr === 2) return '重要';
                    if (pr === 4) return '緊急';
                    if (pr === 5) return '老師交辦';
                    if (pr === 6) return '審查建議';
                    return '其他';
                },
                get_task() {
                    $.post("../modules/T_req&task.php?do=get_task", {
                        team_ID: this.now_team_ID
                    }, item => {
                        this.all_task = JSON.parse(item)
                    })
                },
                // 以上=>GET，搜尋各種資料，於畫面載入時執行
                now_task_click(item) {
                    this.now_task = item;
                    $('#task_look_modal').modal('show');
                },
                task_modal_show(type, id) {
                    if (type === "req") {
                        this.form = {
                            id: null,
                            req_ID: id || null,
                            title: null,
                            desc: null,
                            priority: 5,
                            who_task: null,
                            start_d: null,
                            end_d: null,
                        };
                    } else if (type === "edit") {
                        $('#task_look_modal').modal('hide');
                        this.form = {
                            id: this.now_task.task_ID,
                            req_ID: this.now_task.rd_ID ?? this.now_task.req_ID ?? null,
                            title: this.now_task.task_title,
                            desc: this.now_task.task_desc,
                            priority: 5,
                            who_task: (this.now_task.task_done_u_ID ?? null),
                        };
                    } else {
                        this.form = {
                            id: null,
                            req_ID: null,
                            title: null,
                            desc: null,
                            priority: 5,
                            who_task: null,
                            start_d: null,
                            end_d: null,
                        };
                    }
                    $('#task_modal').modal('show');
                },
                task_modal_close() {
                    $('#task_modal').modal('hide');
                    this.form = {
                        id: null,
                        req_ID: null,
                        title: null,
                        desc: null,
                        priority: 5,
                        who_task: null,
                    };
                },
                task_submit(type) {
                    if (this.form.title == null) toast({
                        type: 'error',
                        title: '請填寫完整資料！'
                    })
                    else {
                        if (type == "new") {
                            $.post("../modules/T_req&task.php?do=new_task_submit", {
                                    form: this.form,
                                    now_team_ID: this.now_team_ID
                                })
                                .done(() => {
                                    $('#task_modal').modal('hide')
                                    this.get_task()
                                    toast({
                                        type: 'success',
                                        title: '新增成功'
                                    })
                                })
                        } else if (type == "edit") {
                            $.post("../modules/T_req&task.php?do=edit_task_submit", {
                                    form: this.form,
                                    id: this.now_task.task_ID,
                                    now_team_ID: this.now_team_ID
                                })
                                .done(() => {
                                    $('#task_modal').modal('hide')
                                    this.get_task()
                                    toast({
                                        type: 'success',
                                        title: '編輯成功'
                                    })
                                })
                        } else if (type == "del") {
                            $.post("../modules/T_req&task.php?do=del_task_submit", {
                                    id: this.now_task.task_ID
                                })
                                .done(() => {
                                    $('#task_modal').modal('hide')
                                    this.get_task()
                                    toast({
                                        type: 'success',
                                        title: '刪除成功'
                                    })
                                })
                        }
                        this.form = {
                            id: null,
                            req_ID: null,
                            title: null,
                            desc: null,
                            priority: 5,
                            who_task: null,
                        }
                    }
                },
                take_task(status) {
                    $.post("../modules/T_req&task.php?do=take_task", {
                            id: this.now_task.task_ID,
                            status: status
                        })
                        .done(() => {
                            $('#task_look_modal').modal('hide')
                            this.get_task()
                            // 🔹 這裡原本是 =（指派），會有 bug，幫你改成 === 比較
                            if (status === 1) {
                                toast({
                                    type: 'success',
                                    title: '接下待辦任務公佈欄囉！'
                                })
                            } else if (status === 0) {
                                toast({
                                    type: 'success',
                                    title: '已放棄該待辦任務公佈欄'
                                })
                            } else if (status === 3) {
                                toast({
                                    type: 'success',
                                    title: '恭喜完成待辦任務公佈欄！'
                                })
                            }
                        })
                },
                statusChipText(task) {
                    if (task.task_status === 0) {
                        return '未署名';
                    }
                    if (task.task_status === 1) {
                        return (task.done_name ? task.done_name + '　' : '') + '進行中';
                    }
                    if (task.task_status === 3) {
                        return (task.done_name ? task.done_name + '　' : '') + '已完成';
                    }
                    return '';
                },
                get_now_group() {
                    $.post("../modules/T_req&task.php?do=get_now_group", item => {
                            this.now_group.ID = JSON.parse(item)["group_ID"]
                            this.now_group.name = JSON.parse(item)["group_name"]
                            this.now_group.team_project_name = JSON.parse(item)["team_project_name"]
                            this.now_cohort_ID = JSON.parse(item)["cohort_ID"]
                        })
                        .done(() => {
                            $.post("../modules/T_req&task.php?do=get_now_teammember", this.now_group, item => {
                                    const data = JSON.parse(item);
                                    this.all_teammumber = data.team_member || [];
                                    this.all_team_ID = data.team_IDs || [];
                                    // 第一次進入頁面 → 設定 now_team_ID
                                    if (!this.now_team_ID && this.all_team_ID.length > 0) {
                                        this.now_team_ID = this.all_team_ID[0].team_ID;
                                    }
                                    // 🌟 依 now_team_ID 取得該專題小組的組員
                                    this.now_teammumber = this.all_teammumber.filter(m => m.team_ID == this.now_team_ID);
                                })
                                .done(() => {
                                    this.get_task();
                                    $.post("../modules/T_req&task.php?do=get_exresultdata", {
                                        team_ID: this.now_team_ID
                                    }, item => {
                                        const data = JSON.parse(item) || {};
                                        this.all_exresultdata = data.exresultdata || data || [];
                                    });
                                })
                                .fail((xhr, status, error) => {
                                    console.error('載入最低專題要求失敗:', status, error, xhr.responseText);
                                    this.all_requirement = [];
                                });
                        })
                        .fail((xhr, status, error) => {
                            console.error('載入群組資訊失敗:', status, error, xhr.responseText);
                        });
                },
                downloadPDF() {
                    const data = this.filtered_requirement;
                    if (!data || data.length === 0) {
                        toast({
                            type: 'error',
                            title: '目前沒有可以匯出的最低專題要求'
                        });
                        return;
                    }

                    // 組 HTML 表格內容
                    let rows = '';
                    data.forEach(r => {
                        const direction = (r.req_direction || '').replace(/\n/g, '<br>');
                        const counts = (r.req_count || []).join(' ');
                        rows += `
                <tr>
                    <td style="border:1px solid #ccc;">
                        <div style="width:15px;height:15px;border-radius:50%;background:${r.color_hex};"></div>
                    </td>
                    <td style="border:1px solid #ccc;">${r.req_title}</td>
                    <td style="border:1px solid #ccc;">${direction}</td>
                    <td style="border:1px solid #ccc;">${counts}</td>
                </tr>`;
                    });
                    const html = `
        <div style="font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 16px;">
            <h2 style="margin-bottom: 12px;">
                ${this.now_cohort_ID}級 ${this.now_group.name} 最低專題要求總覽
            </h2>
            <p style="margin-top:0;margin-bottom:12px;">專題組別：${this.now_group.team_project_name || ''}</p>
                        <table style="border-collapse:collapse; width:100%; font-size:12px;">
                <thead>
                    <tr>
                        <th style="border:1px solid #ccc; width:50px;">顏色</th>
                        <th style="border:1px solid #ccc; width:130px;">標題</th>
                        <th style="border:1px solid #ccc;">說明</th>
                        <th style="border:1px solid #ccc; width:160px;">量化目標</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
        </div>
    `;

                    // 建一個暫時的 DOM 節點給 html2pdf 用
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    document.body.appendChild(wrapper);

                    const opt = {
                        margin: 10,
                        filename: `${this.now_cohort_ID}級${this.now_group.name}最低專題要求總覽.pdf`,
                        html2canvas: {
                            scale: 2
                        }, // 解析度好看一點
                        jsPDF: {
                            unit: 'mm',
                            format: 'a4',
                            orientation: 'portrait'
                        }
                    };

                    html2pdf().set(opt).from(wrapper).save().then(() => {
                        document.body.removeChild(wrapper); // 用完移掉
                    });
                },
                limitDirection(text) {
                    if (!text) return "";
                    text = String(text);
                    // 判斷有沒有換行（支援 \n 或 \r\n）
                    const parts = text.split(/\r?\n/);
                    if (parts.length > 1) {
                        const firstLine = parts[0] || "";
                        const prefix = firstLine.slice(0, 20); // 第一行最多 20 個字
                        return prefix + "...";
                    }
                    // 沒有換行：超過 20 個字才截斷
                    if (text.length > 20) {
                        return text.slice(0, 20) + "...";
                    }
                    // 不超過 20 個字 → 原樣顯示
                    return text;
                },

                // milestone任務公佈欄管理功能milestone任務公佈欄管理功能milestone任務公佈欄管理功能
                async loadMilestoneRequirements() {
                    try {
                        const response = await fetch('api.php?do=get_requirements');
                        if (!response.ok) {
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
                        this.milestoneRequirements = await response.json();
                    } catch (error) {
                        console.error('載入最低專題要求失敗:', error);
                        toast({
                            type: 'error',
                            title: error.message || '載入最低專題要求失敗'
                        });
                    }
                },
                async loadMilestoneTeams() {
                    try {
                        const response = await fetch('api.php?do=get_teams');
                        if (!response.ok) throw new Error('載入失敗');
                        this.milestoneTeams = await response.json();
                    } catch (error) {
                        console.error('載入專題小組失敗:', error);
                        toast({
                            type: 'error',
                            title: '載入專題小組失敗'
                        });
                    }
                },
                async loadMilestones() {
                    try {
                        let url = 'api.php?do=get_milestones';
                        const params = [];

                        if (this.milestoneFilters.team_ID > 0) {
                            params.push(`team_ID=${this.milestoneFilters.team_ID}`);
                        }
                        if (this.milestoneFilters.req_ID > 0) {
                            params.push(`req_ID=${this.milestoneFilters.req_ID}`);
                        }

                        if (params.length > 0) {
                            url += '&' + params.join('&');
                        }

                        const response = await fetch(url);
                        if (!response.ok) {
                            const errorText = await response.text();
                            console.error('API 錯誤回應:', errorText);
                            throw new Error('載入失敗');
                        }
                        const data = await response.json();
                        console.log('載入待辦任務公佈欄公佈欄資料:', data); // 除錯用
                        this.milestoneList = Array.isArray(data) ? data : [];

                        // 如果正在顯示甘特圖，更新時間軸
                        if (this.showGanttView) {
                            this.generateGanttTimeScale();
                        }
                    } catch (error) {
                        console.error('載入待辦任務公佈欄公佈欄失敗:', error);
                        toast({
                            type: 'error',
                            title: '載入待辦任務公佈欄公佈欄失敗'
                        });
                    }
                },
                showMilestoneDetail(milestone) {
                    this.selectedMilestone = milestone;
                },
                closeMilestoneDetail() {
                    this.selectedMilestone = null;
                },
                getMilestoneStatusBarClass(status) {
                    const s = Number(status);
                    if (s === 0) return 'bar-not-started';
                    if (s === 1) return 'bar-in-progress';
                    if (s === 2) return 'bar-rejected';
                    if (s === 3) return 'bar-completed';
                    if (s === 4) return 'bar-review';
                    return 'bar-not-started';
                },
                getMilestoneStatusBadgeClass(status) {
                    if (status === 0) return 'not-started';
                    if (status === 1) return 'in-progress';
                    if (status === 2) return 'rejected';
                    if (status === 3) return 'completed';
                    if (status === 4) return 'review';
                    return '';
                },
                getMilestonePriorityClass(priority) {
                    const p = Number(priority);
                    if (p === 0) return 'priority-normal';
                    if (p === 1) return 'priority-important';
                    if (p === 2) return 'priority-urgent';
                    if (p === 3) return 'priority-super-urgent';
                    return 'priority-normal';
                },
                getMilestonePriorityText(priority) {
                    const p = Number(priority);
                    if (p === 1) return '一般';
                    if (p === 2) return '重要';
                    if (p === 4) return '緊急';
                    if (p === 5) return '老師交辦';
                    if (p === 6) return '審查建議';
                    return '一般';
                },
                formatMilestoneDate(dateString) {
                    if (!dateString) return '未設定';
                    const date = new Date(dateString);
                    if (isNaN(date.getTime())) return '無效日期';
                    return date.toLocaleString('zh-TW', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                },
                formatMilestoneDateTime(dateString) {
                    if (!dateString) return '未設定';
                    const date = new Date(dateString);
                    if (isNaN(date.getTime())) return '無效日期';
                    return date.toLocaleString('zh-TW', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit'
                    });
                },
                editMilestone(milestone) {
                    // 隱藏甘特圖 tooltip
                    this.hideGanttTooltip();

                    this.milestoneForm = {
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
                    this.showEditMilestoneModal = true;
                    this.openMilestoneModal();
                },
                openCreateMilestoneModal() {
                    // 隱藏甘特圖 tooltip（必須立即執行）
                    this.hideGanttTooltip();

                    // 強制清理所有可能殘留的甘特圖 tooltip 元素
                    const allTooltips = document.querySelectorAll('.gantt-custom-tooltip');
                    allTooltips.forEach(tooltip => {
                        tooltip.remove();
                    });

                    this.showCreateMilestoneModal = true;
                    this.showEditMilestoneModal = false;
                    this.openMilestoneModal();
                },
                openMilestoneModal() {
                    // 隱藏甘特圖 tooltip，避免覆蓋 modal（必須立即執行）
                    this.hideGanttTooltip();

                    // 強制清理所有可能殘留的甘特圖 tooltip 元素
                    const allTooltips = document.querySelectorAll('.gantt-custom-tooltip');
                    allTooltips.forEach(tooltip => {
                        tooltip.remove();
                    });

                    // 強制清空 selectedMilestone，確保 milestone-detail-modal 不會擋住編輯 modal
                    this.selectedMilestone = null;

                    // 立即隱藏所有可能擋住的元素
                    this.$nextTick(() => {
                        const detailModals = document.querySelectorAll('.milestone-detail-modal');
                        detailModals.forEach(modal => {
                            modal.style.display = 'none';
                            modal.style.visibility = 'hidden';
                            modal.style.opacity = '0';
                            modal.style.zIndex = '-1';
                            modal.style.pointerEvents = 'none';
                        });

                        // 再次確認清理所有甘特圖 tooltip
                        const tooltips = document.querySelectorAll('.gantt-custom-tooltip');
                        tooltips.forEach(tooltip => {
                            tooltip.remove();
                        });
                    });

                    const modalElement = document.getElementById('milestoneEditModal');
                    if (modalElement && window.bootstrap) {
                        const modal = new bootstrap.Modal(modalElement);

                        modal.show();

                        // z-index 由 CSS 統一管理，不需要在這裡設置
                        // 確保 sidebar 不會攔截點擊事件
                        const sidebar = document.getElementById('layoutSidenav_nav');
                        if (sidebar) {
                            sidebar.style.pointerEvents = 'none';
                        }
                    }
                },
                formatDateTimeLocal(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    if (isNaN(date.getTime())) return '';
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    return `${year}-${month}-${day}T${hours}:${minutes}`;
                },
                async deleteMilestone(milestone) {
                    const result = await Swal.fire({
                        title: '確認刪除',
                        text: `確定要刪除「${milestone.ms_title}」嗎？`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: '確定刪除',
                        cancelButtonText: '取消',
                        reverseButtons: true
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

                        toast({
                            type: 'success',
                            title: '待辦任務公佈欄公佈欄已刪除'
                        });
                        this.loadMilestones();
                        this.closeMilestoneDetail();
                    } catch (error) {
                        console.error('刪除失敗:', error);
                        toast({
                            type: 'error',
                            title: error.message || '刪除失敗'
                        });
                    }
                },
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

                        toast({
                            type: 'success',
                            title: `待辦任務公佈欄公佈欄已${actionText}`
                        });
                        await this.loadMilestones();
                        this.closeMilestoneDetail();
                    } catch (error) {
                        console.error('操作失敗:', error);
                        toast({
                            type: 'error',
                            title: error.message || '操作失敗'
                        });
                    }
                },
                async saveMilestone() {
                    try {
                        this.validateMilestoneTimeRange();
                        if (this.milestoneTimeError) {
                            toast({
                                type: 'error',
                                title: this.milestoneTimeError
                            });
                            return;
                        }

                        const formData = new FormData();
                        Object.keys(this.milestoneForm).forEach(key => {
                            if (this.milestoneForm[key] !== null && this.milestoneForm[key] !== undefined) {
                                formData.append(key, this.milestoneForm[key]);
                            }
                        });

                        const action = this.showEditMilestoneModal ? 'update_milestone' : 'create_milestone';
                        const response = await fetch(`api.php?do=${action}`, {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (!data.ok) throw new Error(data.msg || '儲存失敗');

                        toast({
                            type: 'success',
                            title: this.showEditMilestoneModal ? '待辦任務公佈欄公佈欄已更新' : '待辦任務公佈欄公佈欄已建立'
                        });
                        this.closeMilestoneModal();
                        await this.loadMilestones();
                    } catch (error) {
                        console.error('儲存失敗:', error);
                        toast({
                            type: 'error',
                            title: error.message || '儲存失敗'
                        });
                    }
                },
                validateMilestoneTimeRange() {
                    this.milestoneTimeError = '';
                    if (this.milestoneForm.ms_start_d && this.milestoneForm.ms_end_d) {
                        const startTime = new Date(this.milestoneForm.ms_start_d).getTime();
                        const endTime = new Date(this.milestoneForm.ms_end_d).getTime();

                        if (endTime < startTime) {
                            this.milestoneTimeError = '截止時間不可小於開始時間';
                        }
                    }
                },
                closeMilestoneModal() {
                    const modalElement = document.getElementById('milestoneEditModal');
                    if (modalElement && window.bootstrap) {
                        const modal = bootstrap.Modal.getInstance(modalElement);
                        if (modal) {
                            modal.hide();
                        }
                    }
                    // 清理 Vue 狀態
                    this.showCreateMilestoneModal = false;
                    this.showEditMilestoneModal = false;
                    this.milestoneTimeError = '';
                    this.milestoneForm = {
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
                toggleGanttView() {
                    this.showGanttView = !this.showGanttView;
                    if (this.showGanttView) {
                        // 確保數據已載入
                        if (this.milestoneList.length === 0) {
                            this.loadMilestones();
                        }
                        // 使用 nextTick 確保 DOM 更新後再生成時間軸
                        this.$nextTick(() => {
                            this.generateGanttTimeScale();
                        });
                    } else {
                        this.hideGanttTooltip();
                    }
                },
                generateGanttTimeScale() {
                    try {
                        this.ganttTimeScale = [];

                        // 收集所有任務公佈欄的開始和結束時間
                        const dates = this.filteredMilestoneList
                            .filter(m => m.ms_start_d || m.ms_end_d)
                            .flatMap(m => [m.ms_start_d, m.ms_end_d])
                            .filter(d => d)
                            .map(d => new Date(d).getTime());

                        if (dates.length === 0) {
                            this.ganttTimeScale = [];
                            return;
                        }

                        this.ganttStartDate = new Date(Math.min(...dates));
                        this.ganttEndDate = new Date(Math.max(...dates));

                        // 確保開始日期不會太早
                        const start = new Date(this.ganttStartDate);
                        start.setDate(start.getDate() - 1);

                        const end = new Date(this.ganttEndDate);
                        end.setDate(end.getDate() + 1);

                        // 生成時間刻度（每天一個）
                        this.ganttTimeScale = [];
                        const current = new Date(start);
                        while (current <= end) {
                            this.ganttTimeScale.push(new Date(current).toISOString());
                            current.setDate(current.getDate() + 1);
                        }
                    } catch (error) {
                        console.error('生成甘特圖時間軸失敗:', error);
                        this.ganttTimeScale = [];
                    }
                },
                getGanttBarStyle(milestone) {
                    if (!this.ganttStartDate || !this.ganttEndDate || this.ganttTimeScale.length === 0) {
                        return {};
                    }

                    const containerWidth = this.ganttTimeScale.length * 100;
                    return {
                        width: `${containerWidth}px`,
                        position: 'relative'
                    };
                },
                getGanttBarPosition(milestone) {
                    if (!this.ganttStartDate || !this.ganttEndDate) {
                        return {};
                    }

                    let barStart = milestone.ms_start_d || milestone.ms_end_d || milestone.ms_created_d;
                    let barEnd = milestone.ms_end_d || milestone.ms_start_d || milestone.ms_created_d;

                    if (!barStart || !barEnd) {
                        return {
                            display: 'none'
                        };
                    }

                    const start = new Date(this.ganttStartDate);
                    start.setDate(start.getDate() - 1);
                    start.setHours(0, 0, 0, 0);

                    const barStartTime = new Date(barStart).getTime();
                    const barEndTime = new Date(barEnd).getTime();
                    const startTime = start.getTime();
                    const endTime = this.ganttEndDate.getTime();
                    const totalDuration = endTime - startTime + (24 * 60 * 60 * 1000);
                    const barDuration = barEndTime - barStartTime;

                    const leftPercent = ((barStartTime - startTime) / totalDuration) * 100;
                    const widthPercent = (barDuration / totalDuration) * 100;

                    return {
                        left: `${leftPercent}%`,
                        width: `${Math.max(widthPercent, 1)}%`
                    };
                },
                getGanttBarClass(milestone) {
                    const status = Number(milestone.ms_status);
                    if (status === 3) return 'gantt-bar-completed';
                    if (status === 4) return 'gantt-bar-review';
                    if (status === 2) return 'gantt-bar-rejected';
                    if (milestone.ms_end_d && new Date(milestone.ms_end_d) < new Date()) {
                        return 'gantt-bar-overdue';
                    }
                    return 'gantt-bar-active';
                },
                formatGanttDateShort(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    if (isNaN(date.getTime())) return '';
                    return `${date.getMonth() + 1}/${date.getDate()}`;
                },
                formatGanttDuration(startDate, endDate, milestone) {
                    if (!startDate) return '';

                    const start = new Date(startDate);
                    start.setSeconds(0, 0);

                    const now = new Date();
                    let end = null;
                    let isStopped = false;

                    const status = milestone ? Number(milestone.ms_status) : null;

                    if (status === 3) {
                        if (milestone.ms_approved_d) {
                            end = new Date(milestone.ms_approved_d);
                            isStopped = true;
                        } else if (milestone.ms_completed_d) {
                            end = new Date(milestone.ms_completed_d);
                            isStopped = true;
                        } else {
                            if (endDate && new Date(endDate) < now) {
                                end = new Date(endDate);
                                isStopped = true;
                            } else {
                                end = now;
                            }
                        }
                    } else {
                        end = now;
                    }

                    if (isStopped) {
                        end.setSeconds(0, 0);
                    }

                    const diffMs = end - start;
                    if (diffMs < 0) return '00:00:00';

                    const totalSeconds = Math.floor(diffMs / 1000);
                    const hours = Math.floor(totalSeconds / 3600);
                    const minutes = Math.floor((totalSeconds % 3600) / 60);
                    const seconds = totalSeconds % 60;

                    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                },
                getGanttBarTooltip(milestone) {
                    let tooltip = '';

                    // 格式化開始時間
                    const formatStartTime = (dateString) => {
                        if (!dateString) return '';
                        const date = new Date(dateString);
                        if (isNaN(date.getTime())) return '';
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');
                        const hours = String(date.getHours()).padStart(2, '0');
                        const minutes = String(date.getMinutes()).padStart(2, '0');
                        return `${year}-${month}-${day} ${hours}:${minutes}`;
                    };

                    if (milestone.ms_start_d) {
                        const status = Number(milestone.ms_status);
                        const duration = this.formatGanttDuration(milestone.ms_start_d, milestone.ms_end_d, milestone);
                        const startTime = formatStartTime(milestone.ms_start_d);

                        if (duration) {
                            if (status === 3) {
                                tooltip = `開始時間：${startTime}\n進行時間：${duration}（已完成）`;
                            } else if (status === 4) {
                                tooltip = `開始時間：${startTime}\n進行時間：${duration}（待審核）`;
                            } else if (milestone.ms_end_d && new Date(milestone.ms_end_d) < new Date()) {
                                tooltip = `開始時間：${startTime}\n進行時間：${duration}（已截止）`;
                            } else {
                                tooltip = `開始時間：${startTime}\n進行時間：${duration}（進行中）`;
                            }
                        } else {
                            tooltip = `開始時間：${startTime}`;
                        }
                    } else {
                        tooltip = '進行時間：未開始';
                    }

                    return tooltip;
                },
                showGanttTooltip(event, milestone) {
                    this.hideGanttTooltip();

                    this.ganttCurrentTooltipMilestone = milestone;
                    this.ganttTooltipTargetElement = event.currentTarget;

                    const tooltip = document.createElement('div');
                    tooltip.className = 'gantt-custom-tooltip';

                    this.ganttTooltipElement = tooltip;

                    this.updateGanttTooltipContent();
                    document.body.appendChild(tooltip);

                    // 定期更新 tooltip（如果時間在進行中）
                    this.ganttTooltipTimer = setInterval(() => {
                        if (this.ganttTooltipElement && this.ganttCurrentTooltipMilestone) {
                            this.updateGanttTooltipContent();
                        }
                    }, 1000);
                },
                updateGanttTooltipContent() {
                    if (!this.ganttTooltipElement || !this.ganttCurrentTooltipMilestone) return;

                    const milestone = this.ganttCurrentTooltipMilestone;
                    const tooltipText = this.getGanttBarTooltip(milestone);

                    this.ganttTooltipElement.textContent = tooltipText;

                    if (this.ganttTooltipTargetElement) {
                        const rect = this.ganttTooltipTargetElement.getBoundingClientRect();
                        const tooltipRect = this.ganttTooltipElement.getBoundingClientRect();

                        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
                        let top = rect.top - tooltipRect.height - 10;

                        if (left < 10) left = 10;
                        if (left + tooltipRect.width > window.innerWidth - 10) {
                            left = window.innerWidth - tooltipRect.width - 10;
                        }
                        if (top < 10) {
                            top = rect.bottom + 10;
                        }

                        this.ganttTooltipElement.style.left = left + 'px';
                        this.ganttTooltipElement.style.top = top + 'px';
                    }
                },
                hideGanttTooltip() {
                    if (this.ganttTooltipTimer) {
                        clearInterval(this.ganttTooltipTimer);
                        this.ganttTooltipTimer = null;
                    }

                    if (this.ganttTooltipElement) {
                        this.ganttTooltipElement.remove();
                        this.ganttTooltipElement = null;
                    }

                    this.ganttCurrentTooltipMilestone = null;
                    this.ganttTooltipTargetElement = null;
                },
            },
            mounted() {
                this.get_now_group();
                // 如果預設標籤是任務公佈欄，自動載入任務公佈欄資料
                if (this.activeTab === 'milestone') {
                    this.loadMilestoneRequirements();
                    this.loadMilestoneTeams();
                    this.loadMilestones();
                }

                // 設置任務公佈欄 modal 的事件監聽器，確保關閉時正確清理狀態
                const milestoneModalElement = document.getElementById('milestoneEditModal');
                if (milestoneModalElement) {
                    // 當 modal 顯示時，確保它在最上層並隱藏甘特圖 tooltip
                    milestoneModalElement.addEventListener('show.bs.modal', () => {
                        // 立即隱藏甘特圖 tooltip（必須在第一時間處理）
                        this.hideGanttTooltip();

                        // 強制清理所有可能殘留的甘特圖 tooltip 元素
                        const allTooltips = document.querySelectorAll('.gantt-custom-tooltip');
                        allTooltips.forEach(tooltip => {
                            tooltip.remove();
                        });

                        // 強制清空 selectedMilestone，避免 milestone-detail-modal 擋住
                        this.selectedMilestone = null;

                        // 強制隱藏所有 milestone-detail-modal
                        this.$nextTick(() => {
                            const detailModals = document.querySelectorAll('.milestone-detail-modal');
                            detailModals.forEach(modal => {
                                modal.style.display = 'none';
                                modal.style.visibility = 'hidden';
                                modal.style.opacity = '0';
                                modal.style.zIndex = '-1';
                                modal.style.pointerEvents = 'none';
                            });

                            // 再次確認清理所有甘特圖 tooltip
                            const tooltips = document.querySelectorAll('.gantt-custom-tooltip');
                            tooltips.forEach(tooltip => {
                                tooltip.remove();
                            });
                        });

                        // 讓 sidebar 不會攔截點擊事件（由 CSS 處理 z-index）
                        const sidebar = document.getElementById('layoutSidenav_nav');
                        if (sidebar) {
                            sidebar.style.pointerEvents = 'none';
                        }

                        // 確保 navbar 也不會攔截點擊（除了通知等必要元素）
                        const navbar = document.querySelector('.navbar, .sb-topnav');
                        if (navbar) {
                            // navbar 本身可以點擊，但確保 modal 在上層
                            navbar.style.zIndex = '1030';
                        }
                    });

                    milestoneModalElement.addEventListener('shown.bs.modal', () => {
                        // modal 完全顯示後，再次確保沒有其他元素擋住
                        this.selectedMilestone = null;

                        // 再次確保甘特圖 tooltip 被清理
                        this.hideGanttTooltip();
                        const allTooltips = document.querySelectorAll('.gantt-custom-tooltip');
                        allTooltips.forEach(tooltip => {
                            tooltip.remove();
                        });

                        // 強制隱藏所有 milestone-detail-modal
                        this.$nextTick(() => {
                            const detailModals = document.querySelectorAll('.milestone-detail-modal');
                            detailModals.forEach(modal => {
                                modal.style.display = 'none';
                                modal.style.visibility = 'hidden';
                                modal.style.opacity = '0';
                                modal.style.zIndex = '-1';
                                modal.style.pointerEvents = 'none';
                            });

                            // 再次確認清理所有甘特圖 tooltip
                            const tooltips = document.querySelectorAll('.gantt-custom-tooltip');
                            tooltips.forEach(tooltip => {
                                tooltip.remove();
                            });

                            // 確保 modal 本身的 z-index 和 pointer-events 正確
                            if (milestoneModalElement) {
                                milestoneModalElement.style.zIndex = '';
                                milestoneModalElement.style.pointerEvents = 'auto';

                                const dialog = milestoneModalElement.querySelector('.modal-dialog');
                                const content = milestoneModalElement.querySelector('.modal-content');

                                if (dialog) {
                                    dialog.style.zIndex = '';
                                    dialog.style.pointerEvents = 'auto';
                                }
                                if (content) {
                                    content.style.zIndex = '';
                                    content.style.pointerEvents = 'auto';
                                }

                                // 確保 backdrop 不會攔截點擊（只攔截背景，不攔截 modal）
                                const backdrop = document.querySelector('.modal-backdrop');
                                if (backdrop) {
                                    backdrop.style.pointerEvents = 'auto'; // backdrop 本身應該可以點擊（關閉 modal）
                                }
                            }
                        });
                    });

                    milestoneModalElement.addEventListener('hidden.bs.modal', () => {
                        // 當 modal 完全關閉後（包括動畫完成），清理狀態
                        this.showCreateMilestoneModal = false;
                        this.showEditMilestoneModal = false;
                        this.milestoneTimeError = '';
                        this.milestoneForm = {
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

                        // 恢復 sidebar 的點擊事件
                        const sidebar = document.getElementById('layoutSidenav_nav');
                        if (sidebar) {
                            sidebar.style.pointerEvents = '';
                        }
                    });
                }
            },
        }).mount("#task_app");
    }
</script>