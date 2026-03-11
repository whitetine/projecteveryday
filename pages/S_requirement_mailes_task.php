<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
<link rel="stylesheet" href="css/group_manage.css?v=<?= time() ?>">
<link rel="stylesheet" href="css/task.css?v=<?= time() ?>">
<link rel="stylesheet" href="css/student_milestone.css?v=<?= time() ?>" onload="this.onload=null;this.rel='stylesheet'">
<link rel="stylesheet" href="css/gantt_chart.css?v=<?= time() ?>" onload="this.onload=null;this.rel='stylesheet'">
<link rel="stylesheet" href="css/milestone.css?v=<?= time() ?>" onload="this.onload=null;this.rel='stylesheet'">
<noscript>
    <link rel="stylesheet" href="css/milestone.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/student_milestone.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/gantt_chart.css?v=<?= time() ?>">
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
    <div class="floating-tabs-wrapper">
        <ul class="nav req-task-tabs">
            <li class="nav-item" v-for="tab in tabs" :key="tab.key">
                <button class="nav-link" :class="{ active: activeTab === tab.key }" type="button"
                    @click="changeTab(tab.key)">
                    <i :class="['me-2', tab.icon]"></i>{{ tab.label }}
                </button>
            </li>
        </ul>
    </div>

    <!-- 篩選區域 -->
    <div class="filter-section">
        <div class="filter-card">
            <div class="filter-header">
                <h3 style="font-weight:800">
                    分列方式
                </h3>
                <button class="btn-gantt" @click="task_modal_show">
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
    <!-- 專題需求牆待辦事項 心願待辦事項：統一 board -->
    <div class="todo-board">
        <div class="todo-column" v-for="col in activeColumns" :key="col.key">
            <div class="todo-column-header">
                <h3 class="todo-column-title">{{ col.label }}</h3>
                <span class="todo-column-count">{{ col.tasks.length }}</span>
            </div>

            <div class="todo-column-body">
                <!-- 單張待辦事項待辦事項 -->
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
                        <!-- 標籤 tag -->
                        <span class="todo-note-tag"
                            v-if="task.task_priority"
                            :style="priorityTagStyle(task.task_priority)">
                            # {{ priorityText(task.task_priority) }}
                        </span>


                        <!-- 狀態 + 負責人 -->
                        <span class="req-count-tag"
                            :style="task.task_status === 1 ? 'background:#F8BF63' : (task.task_status === 3 ? 'background:#CAFCBB' : '')">
                            #&ensp;{{ statusChipText(task) }}
                        </span>
                    </div>
                </div>
                <!-- 沒待辦事項的提示 -->
                <div class="todo-empty" v-if="!col.tasks.length">
                    尚未建立待辦事項～
                </div>
            </div>
        </div>
    </div>


    <teleport to="body">
        <!-- 待辦事項task modal -->
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
                        <!-- 左：表單 / 右：預覽 -->
                        <div class="task-modal-grid">
                            <!-- ========== 表單區 ========== -->
                            <div class="task-form">
                                <table class="w-100">


                                    <tr>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text"><b>待辦事項標題</b></span>
                                                <input type="text" v-model="form.title" class="form-control" id="title" maxlength="18">
                                            </div>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <div class="input-group range-group">
                                                <span class="input-group-text"><b>誰的待辦事項</b></span>
                                                <select class="form-select" v-model="form.who_task">
                                                    <option :value="null">暫不部屬</option>
                                                    <option
                                                        v-for="i in (all_teammumber.filter(c => c.role_ID === 6))"
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
                                            <div class="input-group range-group">
                                                <button class="req-filter-btn" :class="{ active: form.priority === 1 }"
                                                    @click="form.priority = 1">一般</button>

                                                <button class="req-filter-btn" :class="{ active: form.priority === 2 }"
                                                    @click="form.priority = 2">重要</button>

                                                <button class="req-filter-btn" :class="{ active: form.priority === 4 }"
                                                    @click="form.priority = 4">緊急</button>
                                            </div>
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
                            <tr>
                                <td>
                                    <div class="input-group" role="group" aria-label="Basic radio toggle button group">
                                        <span class="input-group-text"><b>對應預期成果</b></span>
                                        <select class="form-select" v-model="form.req_ID">
                                            <option v-for="i in all_exresultdata" :value="i.rd_ID">{{i.rd_title}}
                                            </option>
                                        </select>
                                    </div>
                                </td>
                            </tr>

                            </table>
                        </div>

                        <!-- ========== 預覽區 ========== -->
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
                                    <!-- 標籤 tag -->
                                    <span class="todo-note-tag"
                                        v-if="form.priority"
                                        :style="priorityTagStyle(form.priority)">
                                        # {{ priorityText(form.priority) }}
                                    </span>


                                    <!-- 狀態 + 負責人（用 form 推導，不用 task） -->
                                    <span class="req-count-tag"
                                        :style="form.who_task ? 'background:#F8BF63' : ''">
                                        #&ensp;{{ form.who_task ?  all_teammumber.filter(x=>x.team_u_ID===form.who_task)[0]?.u_name+'　進行中' : '未署名' }}
                                    </span>
                                </div>
                            </div>

                            <!-- 可選：再補一個小摘要 -->
                            <div class="task-preview-meta">
                                <div class="meta-row">
                                    <span class="meta-label">對應預期成果：</span>
                                    <span class="meta-text">
                                        {{ all_exresultdata.filter(x=>x.rd_ID===form.req_ID)[0]?.rd_title || '未指定' }}
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

<!-- 查看待辦事項 look task modal -->
<div class="modal fade" id="task_look_modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <!-- 頂端標題列 -->
            <div class="modal-header">
                <h2>待辦事項詳細資料</h2>
                <button data-bs-dismiss="modal"
                    style="background:none;border:none;font-size:28px;cursor:pointer;color:#999;line-height:1;"
                    class="ms-auto">&times;</button>
            </div>

            <!-- 內容卡片：沿用 req-detail-card 樣式 -->
            <div class="modal-body req-detail-card">
                <!-- 標題 + 狀態 -->
                <div class="req-detail-header">
                    <h3 class="req-detail-title">{{ now_task.task_title }}</h3>

                    <!-- 待辦事項狀態標籤 -->
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

                <!-- 說明 -->
                <div class="req-detail-section">
                    <label class="req-detail-label">說明：</label>
                    <p class="req-detail-text">
                        {{ now_task.task_desc || '（無填寫說明）' }}
                    </p>
                </div>

                <!-- 負責人 -->
                <div class="req-detail-section">
                    <label class="req-detail-label">負責人：</label>
                    <p class="req-detail-text">
                        <span v-if="now_task.task_status==0">
                            尚未有人接下這個待辦事項
                        </span>
                        <span v-else>
                            {{
                                        now_task.done_name+(now_task.task_status!=0?(now_task.task_done_d?"　"+now_task.task_done_d:'')+(now_task.task_status==1?'　接下待辦事項':'　完成待辦事項'):'')}}
                        </span>
                    </p>
                </div>



                <!-- 標籤 -->
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

                <!-- 創立資訊 -->
                <div class="req-detail-section">
                    <p class="req-detail-text">
                        創立者：{{ now_task.creator_name }}<br>
                        創立時間：{{ now_task.task_created_d }}
                    </p>
                </div>
            </div>
            <!-- 按鈕區 -->
            <div class="modal-footer" v-if="now_task.task_status!==3">
                <button class="btn btn-secondary" v-if="u_ID==now_task.task_u_ID && now_task.task_status==0"
                    @click="task_modal_show('edit')" style="margin-right: 14px;">
                    編輯
                </button>
                <button class="btn btn-warning" v-if="now_task.task_status==0" style="margin-right: 14px;"
                    @click="take_task(1)">
                    接下
                </button>
                <button class="btn btn-warning"
                    v-if="now_task.task_status==1 && u_ID==now_task.task_done_u_ID"
                    style="margin-right: 14px;" @click="take_task(0)">
                    放棄接下
                </button>
                <button class="btn btn-primary" @click="take_task(3)" v-if="(now_task.task_status==1 && u_ID==now_task.task_done_u_ID) || now_task.task_status==0">
                    完成
                </button>
            </div>
        </div>
    </div>
</div>
</teleport>
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

                    // student milestone學生任務公佈欄功能變數
                    studentMilestones: [],
                    showGanttView: false,
                    ganttTimeScale: [],
                    ganttStartDate: null,
                    ganttEndDate: null,
                    ganttTooltipElement: null,
                    ganttTooltipTimer: null,
                    ganttCurrentTooltipMilestone: null,
                    ganttTooltipTargetElement: null,
                    tabs: [],
                    // 共用變數
                    u_ID: "<?= $_SESSION["u_ID"] ?>",
                    now_team_ID: null,
                    all_teammumber: [],
                    now_group: {
                        ID: "",
                        name: "",
                        team_project_name: ""
                    },
                    now_cohort_ID: null,

                    // task待辦待辦事項功能變數
                    all_task: [],
                    now_task: [],
                    filter: {
                        task_filter: "status",
                        task_filter_status: "",
                        requirement_status: "",
                    },
                    form: {
                        id: null,
                        req_ID: null,
                        title: null,
                        desc: null,
                        priority: 1,
                        who_task: null,
                    },

                    u_ID: "<?= $_SESSION["u_ID"] ?>",
                    all_exresultdata: [],
                };
            },
            computed: {
                // task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能
                filtered_task() {
                    const mineFilter = this.filter.task_filter; // '' or 'mine'
                    const statusFilter = this.filter.task_filter_status; // '', 'notyet', 'taken', 'done'
                    const u_ID = this.u_ID;
                    return this.all_task.filter(item => {
                        // 1️⃣ 先處理「篩選：我的」
                        if (mineFilter === 'mine') {
                            const isCreator = item.task_u_ID === u_ID; // 我建立的待辦待辦事項
                            const isTaker = item.task_done_u_ID === u_ID; // 我接下的待辦待辦事項
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
                // ★ 依待辦待辦事項狀態分成三列（0 未署名、1 進行中、3 已完成）
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
                    // 先根據 all_teammumber 做出每一欄
                    const cols = this.all_teammumber.filter(m => m.role_ID === 6).map(m => ({
                        key: m.team_u_ID, // 用 team_u_ID 當 key
                        label: m.u_name, // 欄位標題顯示姓名
                        tasks: []
                    }));
                    // 做一個 map，之後塞待辦待辦事項比較快
                    const colMap = {};
                    cols.forEach(c => {
                        colMap[c.key] = c;
                    });
                    // 走過所有待辦待辦事項，決定要丟到誰那一欄
                    this.filtered_task.forEach(t => {
                        let ownerId = t.task_u_ID; // 預設是建立者
                        // 如果有接任人，而且狀態不是未署名，就算在接任人身上
                        if (t.task_done_u_ID && Number(t.task_status) !== 0) {
                            ownerId = t.task_done_u_ID;
                        }
                        const col = colMap[ownerId];
                        if (col) {
                            col.tasks.push(t);
                        }
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

                // task



                // student milestone學生任務公佈欄功能computed
                sortedStudentMilestones() {
                    if (!this.studentMilestones || !Array.isArray(this.studentMilestones)) {
                        return [];
                    }
                    const copy = [...this.studentMilestones];
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
                        if (s === 1 || s === 2) return 0;
                        if (s === 0) return 0.5;
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
                studentMilestoneCompletedCount() {
                    if (!this.studentMilestones || !Array.isArray(this.studentMilestones)) return 0;
                    return this.studentMilestones.filter(m => Number(m.ms_status) === 3).length;
                },
                studentMilestoneProgressPercentage() {
                    if (!this.studentMilestones || this.studentMilestones.length === 0) return 0;
                    return Math.round((this.studentMilestoneCompletedCount / this.studentMilestones.length) * 100);
                },
            },
            methods: {
                changeTab(key) {
                    this.activeTab = key;
                    // 切換到任務公佈欄標籤時載入任務公佈欄數據
                    if (key === 'milestone') {
                        this.loadStudentMilestones();
                    }
                },
                // task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能task待辦待辦事項功能
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
                    $.post("../modules/S_req&task.php?do=get_task", {
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
                    if (type == "req") {
                        this.form.req_ID = ""
                    } else if (type == "edit") {
                        $('#task_look_modal').modal('hide')
                        this.form = {
                            id: this.now_task.task_ID,
                            req_ID: this.now_task.req_ID,
                            title: this.now_task.task_title,
                            desc: this.now_task.task_desc,
                            priority: this.now_task.task_priority,
                            who_task: (this.now_task.task_done_ID ?? null),
                        }
                    }
                    $('#task_modal').modal('show')
                },
                task_modal_close() {
                    $('#task_modal').modal('hide')
                    this.form = {
                        id: null,
                        req_ID: null,
                        title: null,
                        desc: null,
                        priority: 1,
                        who_task: null,
                    }
                },
                task_submit(type) {
                    if (this.form.title == null) toast({
                        type: 'error',
                        title: '請填寫完整資料！'
                    })
                    else {
                        if (type == "new") {
                            $.post("../modules/S_req&task.php?do=new_task_submit", {
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
                            $.post("../modules/S_req&task.php?do=edit_task_submit", {
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
                            $.post("../modules/S_req&task.php?do=del_task_submit", {
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
                            priority: 1,
                            who_task: null
                        }
                    }
                },
                take_task(status) {
                    $.post("../modules/S_req&task.php?do=take_task", {
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
                                    title: '接下待辦待辦事項囉！'
                                })
                            } else if (status === 0) {
                                toast({
                                    type: 'success',
                                    title: '已放棄該待辦待辦事項'
                                })
                            } else if (status === 3) {
                                toast({
                                    type: 'success',
                                    title: '恭喜完成待辦待辦事項！'
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

                // requirement最低專題要求功能requirement最低專題要求功能requirement最低專題要求功能requirement最低專題要求功能requirement最低專題要求功能
                get_requirement() {
                    $.post("../modules/S_req&task.php?do=get_now_group", item => {
                            this.now_group.ID = JSON.parse(item)["group_ID"]
                            this.now_group.name = JSON.parse(item)["group_name"]
                            this.now_group.team_project_name = JSON.parse(item)["team_project_name"]
                        })
                        .done(() => {
                            $.post("../modules/S_req&task.php?do=get_now_teammember", this.now_group, item => {
                                    this.all_teammumber = JSON.parse(item)["team_member"]
                                    this.now_team_ID = JSON.parse(item)["team_ID"]
                                })
                                .done(() => {
                                    this.get_task()
                                    $.post("../modules/S_req&task.php?do=get_exresultdata", {
                                        tm: this.all_teammumber,
                                    }, item => {
                                        this.all_exresultdata = JSON.parse(item)["exresultdata"]
                                        this.now_cohort_ID = JSON.parse(item)["cohort"]
                                    })
                                })


                        })
                },
                // 以上=>GET，搜尋各種資料，於畫面載入時執行
                now_requirement_click(item, key) {
                    this.now_requirement = item;
                    this.now_rp = this.all_rp.find(i => i.req_ID == item.req_ID)
                    if (this.now_rp) {
                        this.return_form = {
                            rp_comment: this.now_rp.rp_comment,
                            count2: this.now_rp.rp_count,
                        }
                    }
                    $('#req_look_modal').modal('show');
                },
                req_modal_close() {
                    this.req_return = false
                    this.return_form = {
                        rp_comment: null,
                        count1: null,
                        count2: null,
                        count3: null,
                    }
                    this.req_return_edit = false
                    $('#req_look_modal').modal('hide')
                },
                req_return_click() {
                    if (!this.return_form.rp_comment && !this.return_form.count2) {
                        toast({
                            type: 'error',
                            title: '請輸入完整回報資料'
                        })
                    } else {
                        $.post("../modules/S_req&task.php?do=req_return_click", {
                                now_team_ID: this.now_team_ID,
                                req_ID: this.now_requirement.req_ID,
                                text: this.return_form.rp_comment,
                                count: this.return_form.count2
                            })
                            .done(() => {
                                toast({
                                    type: 'success',
                                    title: '已回報，待指導老師審核'
                                })
                                $('#req_look_modal').modal('hide')
                                this.get_requirement()
                            })
                    }
                },
                req_return_click_again_show() {
                    this.req_return_edit = true
                },
                req_return_click_again() {
                    if (!this.return_form.rp_comment && !this.return_form.count2) {
                        toast({
                            type: 'error',
                            title: '請輸入完整回報資料'
                        })
                    } else {
                        Swal.fire({
                            icon: 'info',
                            title: '確定覆蓋原先的回報資料?',
                            text: '覆蓋後無法復原',
                            showCancelButton: true, // ⭐ 顯示取消按鈕
                            confirmButtonText: '確定', // 可以自訂
                            cancelButtonText: '取消' // 自訂取消文字
                        }).then((result) => {
                            if (result.isConfirmed) {
                                $.post("../modules/S_req&task.php?do=req_return_click_again", {
                                        rp_ID: this.now_rp.rp_ID,
                                        now_team_ID: this.now_team_ID,
                                        req_ID: this.now_requirement.req_ID,
                                        text: this.return_form.rp_comment,
                                        count: this.return_form.count2
                                    })
                                    .done(() => {
                                        toast({
                                            type: 'success',
                                            title: '已再次回報，待指導老師審核'
                                        })
                                        this.req_return_edit = false
                                        $('#req_look_modal').modal('hide')
                                        this.get_requirement()
                                    })
                            }
                        })
                    }
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

                // student milestone學生任務公佈欄功能student milestone學生任務公佈欄功能
                async loadStudentMilestones() {
                    try {
                        const response = await fetch('api.php?do=get_student_milestones');

                        if (!response.ok) {
                            const errorText = await response.text();
                            console.error('API 錯誤回應:', errorText);
                            throw new Error('載入失敗');
                        }

                        const data = await response.json();
                        console.log('載入任務公佈欄資料:', data);

                        if (data.status === 'error') {
                            toast({
                                type: 'error',
                                title: data.message || '無法載入任務公佈欄資料'
                            });
                            return;
                        }

                        this.studentMilestones = Array.isArray(data) ? data : (data.data || []);
                        console.log('任務公佈欄列表已更新，共', this.studentMilestones.length, '筆');

                        // 如果正在顯示甘特圖，更新時間軸
                        if (this.showGanttView) {
                            this.generateGanttTimeScale();
                        }
                    } catch (error) {
                        console.error('載入任務公佈欄失敗:', error);
                        toast({
                            type: 'error',
                            title: error.message || '網路連線錯誤，請稍後再試'
                        });
                    }
                },
                async completeMilestone(milestone) {
                    if (milestone.isSubmitting) {
                        return;
                    }
                    const s = Number(milestone.ms_status);
                    if (s !== 1 && s !== 2) {
                        return;
                    }
                    milestone.isSubmitting = true;

                    try {
                        const formData = new FormData();
                        formData.append('ms_ID', milestone.ms_ID);
                        formData.append('action', 'complete');

                        const response = await fetch('api.php?do=complete_milestone', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();

                        if (data.status === 'error') {
                            milestone.isSubmitting = false;
                            toast({
                                type: 'error',
                                title: data.message || '無法完成任務公佈欄'
                            });
                            return;
                        }

                        await this.loadStudentMilestones();

                        Swal.fire({
                            icon: 'success',
                            title: '已送出',
                            text: '任務公佈欄已提交完成，等待指導老師審查',
                            confirmButtonText: '確定',
                            reverseButtons: true
                        });
                    } catch (error) {
                        console.error('完成任務公佈欄失敗:', error);
                        milestone.isSubmitting = false;
                        toast({
                            type: 'error',
                            title: '網路連線錯誤，請稍後再試'
                        });
                    }
                },
                async acceptMilestone(milestone) {
                    if (milestone.isAccepting) {
                        return;
                    }
                    if (Number(milestone.ms_status) !== 0) {
                        console.warn('任務公佈欄狀態不是 0，無法接取:', milestone.ms_status);
                        return;
                    }
                    milestone.isAccepting = true;

                    try {
                        const formData = new FormData();
                        formData.append('ms_ID', milestone.ms_ID);

                        console.log('發送接待辦待辦事項請求:', milestone.ms_ID);

                        const response = await fetch('api.php?do=accept_milestone', {
                            method: 'POST',
                            body: formData
                        });

                        // 檢查 HTTP 狀態
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }

                        const data = await response.json();
                        console.log('API 回應:', data);

                        // 檢查錯誤：支援兩種格式 {status: 'error'} 或 {ok: false}
                        if (data.status === 'error' || data.ok === false) {
                            milestone.isAccepting = false;
                            toast({
                                type: 'error',
                                title: data.message || data.msg || '無法接取待辦待辦事項'
                            });
                            return;
                        }

                        // 檢查成功：後端返回 {ok: true, ...}
                        if (data.ok === true) {
                            await this.loadStudentMilestones();

                            Swal.fire({
                                icon: 'success',
                                title: '待辦待辦事項已接取',
                                text: data.message || '開始計時，請開始完成待辦待辦事項',
                                confirmButtonText: '確定',
                                reverseButtons: true
                            });
                        } else {
                            // 未知的回應格式
                            console.warn('未知的 API 回應格式:', data);
                            milestone.isAccepting = false;
                            toast({
                                type: 'error',
                                title: '伺服器回應格式錯誤'
                            });
                        }
                    } catch (error) {
                        console.error('接待辦待辦事項失敗:', error);
                        milestone.isAccepting = false;
                        toast({
                            type: 'error',
                            title: error.message || '網路連線錯誤，請稍後再試'
                        });
                    }
                },
                getStudentMilestoneStatusClass(status) {
                    if (status === 0) return 'status-not-started';
                    if (status === 1) return 'status-in-progress';
                    if (status === 2) return 'status-rejected';
                    if (status === 3) return 'status-completed';
                    if (status === 4) return 'status-review';
                    return '';
                },
                getStudentMilestoneStatusBadgeClass(status) {
                    if (status === 0) return 'not-started';
                    if (status === 1) return 'in-progress';
                    if (status === 2) return 'rejected';
                    if (status === 3) return 'completed';
                    if (status === 4) return 'review';
                    return '';
                },
                getStudentMilestoneStatusText(status) {
                    if (status === 0) return '還未開始';
                    if (status === 1) return '進行中';
                    if (status === 2) return '退回';
                    if (status === 3) return '已完成';
                    if (status === 4) return '待審核';
                    return '未知狀態';
                },
                getStudentMilestonePriorityClass(priority) {
                    if (priority === 0) return 'priority-normal';
                    if (priority === 1) return 'priority-important';
                    if (priority === 2) return 'priority-urgent';
                    if (priority === 3) return 'priority-super-urgent';
                    return 'priority-normal';
                },
                getStudentMilestonePriorityText(priority) {
                    if (priority === 0) return '一般';
                    if (priority === 1) return '重要';
                    if (priority === 2) return '緊急';
                    if (priority === 3) return '超級緊急';
                    return '一般';
                },
                formatStudentMilestoneDate(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    if (isNaN(date.getTime())) return '';
                    return date.toLocaleDateString('zh-TW', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit'
                    });
                },
                formatStudentMilestoneDateTime(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    if (isNaN(date.getTime())) return '';
                    return date.toLocaleString('zh-TW', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                },
                getActionButtonText(milestone) {
                    const s = Number(milestone.ms_status);
                    if (milestone.isSubmitting) return '提交中...';
                    if (s === 0) return '接待辦待辦事項';
                    if (s === 3) return '已完成';
                    if (s === 4) return '等待審查中';
                    return '提交完成';
                },
                isActionDisabled(milestone) {
                    const s = Number(milestone.ms_status);
                    if (milestone.isSubmitting) return true;
                    if (milestone.isAccepting) return true;
                    if (s === 3) return true;
                    if (s === 4) return true;
                    return false;
                },
                showAcceptButton(milestone) {
                    return Number(milestone.ms_status) === 0;
                },
                showCompleteButton(milestone) {
                    const s = Number(milestone.ms_status);
                    return s === 1 || s === 2;
                },
                toggleGanttView() {
                    this.showGanttView = !this.showGanttView;
                    if (this.showGanttView) {
                        this.generateGanttTimeScale();
                    } else {
                        this.hideGanttTooltip();
                    }
                },
                generateGanttTimeScale() {
                    if (!this.studentMilestones || this.studentMilestones.length === 0) {
                        this.ganttTimeScale = [];
                        return;
                    }

                    // 從任務公佈欄中計算日期範圍
                    const dates = this.studentMilestones
                        .map(m => [m.ms_start_d, m.ms_end_d])
                        .flat()
                        .filter(d => d)
                        .map(d => new Date(d));

                    if (dates.length === 0) {
                        this.ganttTimeScale = [];
                        return;
                    }

                    this.ganttStartDate = new Date(Math.min(...dates));
                    this.ganttEndDate = new Date(Math.max(...dates));

                    const start = new Date(this.ganttStartDate);
                    const end = new Date(this.ganttEndDate);
                    const days = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
                    const scaleDays = Math.max(30, Math.min(90, days));

                    this.ganttTimeScale = [];
                    const current = new Date(start);
                    for (let i = 0; i <= scaleDays; i += 7) {
                        const date = new Date(current);
                        date.setDate(date.getDate() + i);
                        if (date <= end) {
                            this.ganttTimeScale.push(date.toISOString());
                        }
                    }
                },
                getGanttBarStyle(milestone) {
                    if (!this.ganttStartDate || !this.ganttEndDate || this.ganttTimeScale.length === 0) {
                        return {};
                    }

                    const containerWidth = this.ganttTimeScale.length * 100;
                    return {
                        width: `${containerWidth}px`
                    };
                },
                getGanttBarPosition(milestone) {
                    if (!this.ganttStartDate || !this.ganttEndDate) {
                        return {
                            left: '0%',
                            width: '0%'
                        };
                    }

                    let barStart = milestone.ms_start_d || milestone.ms_end_d || milestone.ms_created_d;
                    let barEnd = milestone.ms_end_d || milestone.ms_start_d || milestone.ms_created_d;

                    if (!barStart || !barEnd) {
                        return {
                            left: '0%',
                            width: '0%'
                        };
                    }

                    const start = new Date(this.ganttStartDate);
                    const end = new Date(this.ganttEndDate);
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
                getGanttBarClass(milestone) {
                    const status = Number(milestone.ms_status);
                    if (status === 0) return 'not-started';
                    if (status === 1) return 'in-progress';
                    if (status === 2) return 'rejected';
                    if (status === 3) return 'completed';
                    if (status === 4) return 'review';
                    return 'not-started';
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
                    this.ganttTooltipElement = tooltip;

                    this.updateGanttTooltipContent();

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

                    if (!tooltipText) return;

                    this.ganttTooltipElement.textContent = tooltipText;

                    if (this.ganttTooltipTargetElement) {
                        const rect = this.ganttTooltipTargetElement.getBoundingClientRect();
                        const tooltipRect = this.ganttTooltipElement.getBoundingClientRect();

                        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
                        let top = rect.top - tooltipRect.height - 8;

                        if (left < 10) left = 10;
                        if (left + tooltipRect.width > window.innerWidth - 10) {
                            left = window.innerWidth - tooltipRect.width - 10;
                        }
                        if (top < 10) {
                            top = rect.bottom + 8;
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
                this.get_requirement();
                if (this.activeTab === 'milestone') {
                    this.loadStudentMilestones();
                }
            },
        }).mount("#task_app");
    }
</script>