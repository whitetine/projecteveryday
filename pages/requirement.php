<?php
session_start();
?>
<noscript>
    <link rel="stylesheet" href="css/milestone.css?v=<?= time() ?>">
</noscript>
<link rel="stylesheet" href="css/file_manage.css?v=<?= time() ?>">
<link rel="stylesheet" href="css/group_manage.css?v=<?= time() ?>">
<link rel="stylesheet" href="css\team_manage.css">
<style>
    input[type="color"].form-control {
        height: calc(2.5rem + 2px);
        /* 設定input:color高度 ， 跟 Bootstrap 5 的 input 高度一致 */
        padding: 0.25rem;
    }

    /* 讓底部操作列三個東西橫向排好、不要互相擠爆 */
    .user-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        /* 中間留一點距離 */
        margin-top: 8px;
    }

    /* 左邊「選擇」這塊給固定寬度，避免佔太大 */
    .user-actions .user-select-checkbox {
        flex: 0 0 70px;
        /* 你可以改成 60 / 80 去試 */
    }

    /* 按鈕平均分配剩下的寬度 */
    .user-actions>button {
        flex: 1 1 0;
        white-space: nowrap;
        /* 按鈕文字不要自動換行 */
    }

    /* 讓 modal 永遠在 backdrop 前面 */
    .modal {
        z-index: 2000 !important;
    }

    /* 讓黑底永遠在 modal 後面 */
    .modal-backdrop {
        z-index: 1990 !important;
    }

    .swal2-container {
        z-index: 999999 !important;
    }

    /* ✅ Word 風格：表頭深色 + 內容隔行換色 */
    .groups-table {
        width: 100%;
        border-collapse: separate;
        /* 讓圓角/陰影更好看 */
        border-spacing: 0;
        background: #fff;
        border: 1px solid #e6e9f2;
        border-radius: 12px;
        overflow: hidden;
    }

    /* 表頭 */
    .groups-table thead th {
        background: #5b93d3;
        /* 類似你截圖的藍 */
        color: #fff;
        font-weight: 700;
        text-align: center;
        padding: 12px 10px;
        border-bottom: 1px solid rgba(255, 255, 255, .35);
        white-space: nowrap;
    }

    /* 表格格線 */
    .groups-table th,
    .groups-table td {
        border-right: 1px solid #e8eef7;
    }

    .groups-table th:last-child,
    .groups-table td:last-child {
        border-right: 0;
    }

    /* 內容列 */
    .groups-table tbody td {
        padding: 12px 10px;
        vertical-align: middle;
    }

    /* ✅ 隔行換色（Word 最像的重點） */
    .groups-table tbody tr:nth-child(odd) {
        background: #ffffff;
    }

    .groups-table tbody tr:nth-child(even) {
        background: #eef5ff;
    }

    /* 淡藍 */

    /* 滑過高亮（可選，但會更像表格工具的感覺） */
    .groups-table tbody tr:hover {
        background: #dfeeff;
    }

    /* 讓你點整列勾選時，視覺更明顯（可選） */
    .groups-table tbody tr:has(.user-checkbox:checked) {
        outline: 2px solid rgba(111, 100, 255, .35);
        /* 跟你主紫系統搭 */
        outline-offset: -2px;
    }

    .groups-table thead th {
        position: sticky;
        top: 0;
        z-index: 5;
    }

/* 整張表表頭、內容置中 */
.team-excel-table th,
.team-excel-table td {
    text-align: center !important;
    vertical-align: middle !important;
}

/* 每個儲存格裡常見容器也一起置中 */
.team-excel-table td > div,
.team-excel-table td > span,
.team-excel-table th > div,
.team-excel-table th > span {
    text-align: center !important;
    margin-left: auto !important;
    margin-right: auto !important;
}

/* 第一欄 checkbox + 文字 置中 */
.team-excel-table .form-check.user-select-checkbox {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding-left: 0 !important;
    margin: 0 auto !important;
    width: 100% !important;
}

.team-excel-table .form-check-input {
    margin: 0 !important;
    float: none !important;
}

.team-excel-table .form-check-label {
    margin: 0 !important;
    text-align: center !important;
}

/* 達成指標置中 */
.team-excel-table .count-tags {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    gap: 6px !important;
    width: 100% !important;
    text-align: center !important;
}

/* 標題文字區塊置中 */
.team-excel-table .text-truncate-2 {
    text-align: center !important;
    margin: 0 auto !important;
}

/* badge / pill 置中 */
.team-excel-table .badge,
.team-excel-table .pill {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}

/* 操作按鈕群組置中 */
.team-excel-table .btn-group {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    width: 100% !important;
}
</style>

<div id="req_app" class="container my-4">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fa-solid fa-layer-group me-2" style="color: #ffc107;"></i>最低專題要求管理
        </h1>
    </div>
    <button @click="new_requirement_all_show" class="btn btn-primary">新增科上最低專題要求</button>
    <br><br>
    <!-- 搜尋和篩選區 --><!-- T1114抓整合過的 只改文字 -->
    <div class="card mb-4 shadow-sm filter-card">
        <div class="card-header filter-header">
            <i class="fa-solid fa-filter me-2"></i>搜尋與篩選
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">
                        <i class="fa-solid fa-magnifying-glass me-2"></i>搜尋標題名稱
                    </label>
                    <input type="text" class="form-control" v-model="searchText" placeholder="輸入標題名稱..."
                        @input="filter_change_req">
                </div>
                <div class="col-md-2">
                    <label class="form-label">
                        <i class="fa-solid fa-toggle-on me-2"></i>狀態
                    </label>
                    <select class="form-select" v-model="statusFilter" @change="filter_change_req">
                        <option value="">全部</option>
                        <option value="1">啟用</option>
                        <option value="0">停用</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">
                        <i class="fa-solid fa-star me-2"></i>類組篩選
                    </label>
                    <select class="form-select" v-model="searchGroup" @change="filter_change_req">
                        <option value="">全部</option>
                        <option :value="i.group_ID" v-for="i in group">{{i.group_name}}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">
                        <i class="fa-solid fa-star me-2"></i>屆別篩選
                    </label>
                    <select class="form-select" v-model="searchCohort" @change="filter_change_req">
                        <option value="">全部</option>
                        <option :value="i.cohort_ID" v-for="i in cohort">{{i.year_label}}</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary w-100" @click="clearFilters">
                        <i class="fa-solid fa-xmark me-2"></i>清除
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- 顯示當前最低專題要求設定表 -->
    <div class="groups-list-card">
        <div class="card-header">
            <h5>
                <i class="fa-solid fa-list"></i>最低專題要求
            </h5>
            <span class="badge-count">共 {{this.filter_allreq.length}} 筆</span>
        </div>
        <div class="form-check user-select-checkbox" v-if="!tableORcard">
            <input class="form-check-input" type="checkbox" id="select_all_req" :checked="isAllSelected"
                @change="toggleSelectAll($event)">
            <label class="form-check-label" for="select_all_req">
                全選
            </label>
        </div>
        <!-- ✅ 批次操作列：只有在有勾選時才出現 -->
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span>已選 {{ selectedReqIDs.length }} 筆</span>
                <button class="btn btn-sm btn-success" @click="bulkChangeStatus(1)">
                    批次啟用
                </button>
                <button class="btn btn-sm btn-danger" @click="bulkChangeStatus(0)">
                    批次停用
                </button>
                <button class="btn btn-sm btn-outline-secondary" @click="clearSelection">
                    清除選取
                </button>
            </div>
        </div>

        <!-- 清單顯示，若v-if不成立，該區塊不會載入 -->
        <div class="team-groups-container" style="padding: 0;" v-if="tableORcard">
            <table class="team-excel-table table-clean">
                <colgroup>
                    <col width="110px">
                    <col width="70px">
                    <col>
                    <col width="90px">
                    <col>
                    <col width="120px">
                    <col width="240px">
                </colgroup>
                <thead>
                    <tr>
                        <th>
                            <div class="form-check user-select-checkbox">
                                <input class="form-check-input" type="checkbox" id="select_all_req"
                                    :checked="isAllSelected" @change="toggleSelectAll($event)">
                                <label class="form-check-label" for="select_all_req">
                                    全選
                                </label>
                            </div>
                        </th>
                        <th>屆別</th>
                        <th>標題</th>
                        <th>達成指標</th>
                        <th>類組</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="(i,key) in filter_allreq" :key="i.req_ID"
                        @click="rowToggleSelect(i, $event)">
                        <td>
                            <div class="form-check user-select-checkbox">
                                <input class="form-check-input user-checkbox" type="checkbox"
                                    :id="'req_table_' + i.req_ID" :value="i.req_ID" v-model="selectedReqIDs">
                                <label class="form-check-label" :for="'req_table_' + i.req_ID">
                                    選擇
                                </label>
                            </div>
                        </td>

                        <td>
                            <span class="pill pill-soft">{{ i.cohort_name }}</span>
                        </td>

                        <td>
                            <div class="text-truncate-2 fw-semibold" :title="i.req_title">
                                {{ i.req_title }}
                            </div>
                        </td>
                        <td>
                            <div class="count-tags">
                                <template v-for="(t, idx) in safeReqCount(i.req_count)" :key="idx">
                                    <span class="tag">{{ t }}</span>
                                </template>
                            </div>
                        </td>
                        <td>
                            <span class="badge status-badge"
                                :style="groupPillStyle(i.group_name)">
                                {{ i.group_name }}
                            </span>
                        </td>


                        <td>
                            <span class="badge status-badge"
                                :class="i.req_status==1 ? 'status-on' : 'status-off'">
                                {{ i.req_status==1 ? '啟用' : '停用' }}
                            </span>

                        </td>

                        <td>
                            <div class="btn-group btn-group-sm" role="group">
                                <button @click="req_look_modal(i)" class="btn btn-info">查看詳細</button>
                                <button @click="req_edit_modal(i)" class="btn btn-primary">編輯</button>
                                <button @click="req_del(i.req_ID,0)" class="btn btn-danger" v-if="i.req_status==1">停用</button>
                                <button @click="req_del(i.req_ID,1)" class="btn btn-success" v-else>啟用</button>
                            </div>
                        </td>
                    </tr>

                    <tr v-if="!filter_allreq || filter_allreq.length===0">
                        <td colspan="10" class="text-center text-muted py-4">
                            目前沒有符合條件的資料
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <!-- 新增科上最低專題要求 彈跳視窗modal -->
    <teleport to="body">
        <div class="modal fade" id="new_requirement_all" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title" id="roleLabel">
                            <b>新增{{role_ID==1||role_ID==2?"科上":role_ID==4?"團隊":""}}最低專題要求</b>
                        </h3>
                        <i class="fa-solid fa-square-xmark ms-auto" style="font-size: 24px; cursor:pointer;"
                            @click="new_requirement_all_close"></i>
                    </div>
                    <div class="modal-body text-center">
                        <div class="btn-group" role="group" aria-label="Basic radio toggle button group">
                            <span class="input-group-text"><b>選擇類組</b></span>
                            <template v-for="i in group">
                                <input type="radio" class="btn-check" :name="'btnradio'" :id="i.group_ID"
                                    autocomplete="off" :value="i.group_ID" @click="new_progress.group_ID=i.group_ID"
                                    v-model="new_progress.group_ID">
                                <label class="btn btn-outline-primary" :for="i.group_ID">{{ i.group_name }}</label>
                            </template>
                        </div>
                        <input type="hidden" v-model="form.req_ID" name="req_ID" v-if="form.req_ID">
                        <input type="hidden" v-model="new_progress.group_ID" name="ID">
                        <input type="hidden" v-model="new_progress.team_ID" name="tID">
                        <table width="100%" style="text-align: center;margin-top: 10px;">
                            <tr>
                                <td>
                                    <div class="input-group" role="group" aria-label="Basic radio toggle button group"
                                        v-if="role_ID==1||role_ID==2">
                                        <span class="input-group-text"><b>指定屆別</b></span>
                                        <select class="form-select" name="cohort" id="cohort" v-model="form.cohort_ID">
                                            <option :value="i.cohort_ID" v-for="i in cohort">{{i.cohort_name}}
                                            </option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2"><span style="color:gray">數字、單位非必填，若需設立目標數字才需填寫！</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <!-- <div class="input-group">
                                        <span class="input-group-text"><b>進度標題</b></span>
                                        <input type="text" class="form-control" name="title" id="title"
                                            v-model="form.req_title">
                                    </div> -->
                                    <div class="input-group">
                                        <!-- <span class="input-group-text"><b>量化目標</b></span> -->
                                        <input type="text" class="form-control" placeholder="進度標題"
                                            :name="'count_one[]'" style="width: 50%;" v-model="form.req_title" id="title">
                                        <input type="number" class="form-control" placeholder="數字" :name="'count_two[]'"
                                            min="1" v-model="form.count2">
                                        <input type="text" class="form-control" placeholder="單位(ex:人)"
                                            :name="'count_three[]'" style="width: 15%;" v-model="form.count3">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>&emsp;</td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>進度說明<br>(非必填)</b></span>
                                        <textarea class="form-control" rows="4" name="describe" style="resize: none;"
                                            id="describe" v-model="form.req_direction"></textarea>
                                    </div>
                                </td>
                            </tr>
                            <!-- <tr>
                                <td>
                                    <div class="input-group" v-for="i in new_progress.count_number">
                                        <span class="input-group-text"><b>量化目標</b></span>
                                        <input type="text" class="form-control" placeholder="目標(ex:粉絲數)"
                                            :name="'count_one[]'" style="width: 25%;" v-model="form.count1">
                                        <input type="number" class="form-control" placeholder="數字" :name="'count_two[]'"
                                            min="1" v-model="form.count2">
                                        <input type="text" class="form-control" placeholder="單位(ex:人)"
                                            :name="'count_three[]'" style="width: 10%;" v-model="form.count3">
                                    </div>
                                </td>
                            </tr> -->
                        </table>
                    </div>
                    <div class="modal-footer">
                        <input type="button" class="btn btn-primary" :value="form.req_ID?'送出編輯':'確定新增'"
                            @click="new_p_submit">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="req_look_modal_ID">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><b>詳細資料</b></h3>
                        <i class="fa-solid fa-square-xmark ms-auto" style="font-size: 24px; cursor:pointer;"
                            @click="req_look_modal_close"></i>
                    </div>
                    <div class="modal-body">
                        <table width="100%">
                            <tr>
                                <td>屆別：</td>
                                <td>{{form.cohort_name}}</td>
                            </tr>
                            <tr>
                                <td>標題：</td>
                                <td>{{form.req_title}}</td>
                            </tr>
                            <tr>
                                <td>說明：</td>
                                <td><textarea class="form-control" style="resize: none;" readonly>{{form.req_direction==""?"暫無說明。":form.req_direction}}</textarea></td>
                            </tr>
                            <tr>
                                <td>達成指標：</td>
                                <td>
                                    <div class="count-tags">
                                        <template v-for="(t, idx) in safeReqCount(form.req_count)" :key="idx" v-if="form.req_count!='[]'">
                                            <span class="tag">{{ t }}</span>
                                        </template>
                                        <span v-else>暫無達成指標。</span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>類組：</td>
                                <td><span class="badge status-badge"
                                        :style="groupPillStyle(form.group_name)">
                                        {{ form.group_name }}
                                    </span></td>
                            </tr>
                            <tr>
                                <td>狀態：</td>
                                <td>{{form.req_status==1?'啟用':'停用'}}</td>
                            </tr>
                            <tr>
                                <td>創建者</td>
                                <td>{{form.u_name}}</td>
                            </tr>
                            <tr>
                                <td>創建時間</td>
                                <td>{{form.req_created_d}}</td>
                            </tr>
                            <tr v-if="form.req_update_d!='0000-00-00 00:00:00'">
                                <td>最後更新時間</td>
                                <td>{{form.req_update_d}}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</div>
<script>
    // 小視窗的
    function toast({
        type = 'info',
        title = '',
        text = '',
        ms = 3000
    } = {}) {
        Swal.fire({
            toast: true,
            position: 'bottom-end', // 🔹右下角
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
            } // 套用上面 CSS 樣式
        });
    }

    // 只初始化一次 requirement Vue App
    if (!window._reqAppInitialized) {
        window._reqAppInitialized = true;

        // 清理函式
        function cleanupReqApp() {
            if (window.reqVueApp && typeof window.reqVueApp.unmount === 'function') {
                try {
                    window.reqVueApp.unmount();
                    window.reqVueApp = null;
                } catch (e) {
                    console.warn('卸載 requirement app 時出錯:', e);
                }
            }
            // 重置標記，允許重新初始化
            window._reqAppInitialized = false;
        }

        // 如果已經存在 app，先卸載
        cleanupReqApp();

        // 監聽頁面切換事件，自動清理
        // 移除舊的監聽器（如果存在），避免重複監聽
        const oldHandler = window._reqCleanupHandler;
        if (oldHandler) {
            window.removeEventListener('pageBeforeUnload', oldHandler);
        }
        window._reqCleanupHandler = cleanupReqApp;
        window.addEventListener('pageBeforeUnload', cleanupReqApp);

        // ✅ 建立新的 Vue App
        window.reqVueApp = Vue.createApp({
            data() {
                return {
                    role_ID: "<?= $_SESSION['role_ID']; ?>",
                    group: [],
                    team: [],
                    new_progress: {
                        group_ID: 0,
                        count_number: 1,
                    },
                    enddate: null,
                    startdate: null,
                    today: '',
                    cohort: [],
                    type: [],
                    filter_allreq: [],
                    allreq: [], // 補上 allreq，給篩選用
                    form: {
                        count1: "",
                        count2: "",
                        count3: "",
                        req_ID: "",
                        cohort_ID: "",
                        type_ID: "",
                        req_title: "",
                        req_direction: "",
                        color_hex: "#FFEE66",
                        group_ID: "",
                    },
                    statusFilter: "1",
                    searchText: "",
                    searchGroup: "",
                    searchCohort: "",
                    tableORcard: true,
                    isPressed: false,
                    selectedReqIDs: [], // 存被勾選的 req_ID
                }
            },
            computed: {
                // ✅ 判斷目前畫面上的是否都被選取
                isAllSelected() {
                    return (
                        this.filter_allreq.length > 0 &&
                        this.filter_allreq.every(item => this.selectedReqIDs.includes(item.req_ID))
                    );
                },
            },

            methods: {
                // 把字串轉成穩定的整數（同字串 => 同結果）
                hashString(str) {
                    str = String(str ?? '');
                    let hash = 5381;
                    for (let i = 0; i < str.length; i++) {
                        hash = ((hash << 5) + hash) ^ str.charCodeAt(i);
                    }
                    return hash >>> 0; // unsigned
                },
                groupColorHsl(groupName) {
                    const hash = this.hashString(groupName);

                    // 🎨 把 hash 拆成多個維度
                    const h = hash % 360; // 色相
                    const s = 55 + (hash % 30); // 55~85 飽和度
                    const l = 88 + (hash % 6); // 88~94 亮度（仍然淡）
                    const borderL = l - 10; // 邊框深一點
                    const textL = 25 + (hash % 15); // 文字深色但有差異

                    return {
                        bg: `hsl(${h} ${s}% ${l}%)`,
                        border: `hsl(${h} ${s}% ${borderL}%)`,
                        text: `hsl(${h} ${s}% ${textL}%)`
                    };
                },

                groupPillStyle(groupName) {
                    const c = this.groupColorHsl(groupName);
                    return {
                        backgroundColor: c.bg,
                        borderColor: c.border,
                        color: c.text
                    };
                },
                safeReqCount(req_count) {
                    try {
                        if (!req_count || req_count === "[]") return [];
                        const arr = JSON.parse(req_count);
                        if (!Array.isArray(arr)) return [];
                        // 過濾空值，避免顯示 undefined
                        return arr.filter(x => x !== null && x !== undefined && String(x).trim() !== "");
                    } catch (e) {
                        return [];
                    }
                },
                // ✅ 點整列可切換勾選（排除按鈕/連結/輸入框等可互動元素）
                rowToggleSelect(i, evt) {
                    // 如果點到的是「可互動元素」，就不要觸發列勾選
                    const ignoreSelector = 'input, label, button, a, select, textarea, .btn, .form-check, .btn-group';
                    if (evt && evt.target && evt.target.closest(ignoreSelector)) return;

                    const id = i.req_ID;
                    const idx = this.selectedReqIDs.indexOf(id);

                    if (idx === -1) this.selectedReqIDs.push(id);
                    else this.selectedReqIDs.splice(idx, 1);
                },
                get_req_ch() {
                    $.post("../modules/requirement.php?do=get_req_ch", item => {
                        this.filter_allreq = JSON.parse(item)
                        this.allreq = JSON.parse(item)
                        this.filter_change_req();
                    })
                },
                req_del(ID, number) {
                    $.post("../modules/requirement.php?do=req_del", {
                            ID: ID,
                            number: number
                        })
                        .done(() => {
                            this.get_req_ch()
                        })
                    toast({
                        type: 'success',
                        title: '狀態已更新'
                    });
                },
                req_edit_modal(key) {
                    this.form = key
                    this.new_progress.group_ID = this.form.group_ID
                    if (this.form.req_count != "[]") {
                        this.form.count1 = JSON.parse(this.form.req_count)[0]
                        this.form.count2 = JSON.parse(this.form.req_count)[1]
                        this.form.count3 = JSON.parse(this.form.req_count)[2]
                    }
                    $("#new_requirement_all").modal("show")
                },
                select_group() {
                    $.post("../modules/requirement.php?do=get_all_group", item => {
                        this.group = JSON.parse(item)
                    })
                },
                select_team() {
                    $.post("../modules/requirement.php?do=select_team", item => {
                        this.team = JSON.parse(item)
                    })
                },
                get_cohortANDtype() {
                    $.post("../modules/requirement.php?do=get_cohort", item => {
                        this.cohort = JSON.parse(item)
                    })
                    $.post("../modules/requirement.php?do=get_type", item => {
                        this.type = JSON.parse(item)
                    })
                },
                new_requirement_all_show() {
                    this.get_cohortANDtype()
                    $('#new_requirement_all').modal('show')
                },
                new_requirement_all_close() {
                    $('#new_requirement_all').modal('hide')
                    this.new_progress.count_number = 1
                    this.form = {
                        count1: "",
                        count2: "",
                        count3: "",
                        req_ID: "",
                        cohort_ID: "",
                        type_ID: "",
                        req_title: "",
                        req_direction: "",
                        req_end_d: "",
                        color_hex: "#FFEE66",
                        group_ID: "",
                    }
                    this.new_progress.group_ID = ""
                },
                req_look_modal_close() {
                    $('#req_look_modal_ID').modal('hide')
                },
                new_p_submit() { //送出編輯 & 確定新增
                    this.form.type_ID = 1
                    if (
                        !document.getElementById("title").value ||
                        (!this.new_progress.group_ID && !this.new_progress.team_ID) ||
                        !document.getElementById("cohort").value
                    ) {
                        toast({
                            type: 'error',
                            title: '送出失敗',
                            text: '請輸入完整資料！(類組、屆別、標題)'
                        })
                    } else {
                        this.form.group_ID = this.new_progress.group_ID
                        $.post("../modules/requirement.php?do=new_requirement_all", this.form)
                            .done(() => {
                                this.get_req_ch()
                                toast({
                                    type: 'success',
                                    title: '資料已送出',
                                    text: '感謝您的填寫！'
                                })
                                $('#new_requirement_all').modal('hide')
                                this.new_requirement_all_close()
                            })
                    }
                },
                toggleButton() {
                    this.isPressed = !this.isPressed
                },
                go_type() {
                    location.href = "main.php#pages/type.php";
                    this.new_requirement_all_close()
                },
                clearFilters() {
                    // 篩選 清除按鈕
                    this.statusFilter = "1"
                    this.searchText = ""
                    this.searchGroup = ""
                    this.searchCohort = ""
                    this.filter_allreq = this.allreq
                },
                filter_change_req() {
                    this.filter_allreq = this.allreq.filter(item =>
                        item.req_title.includes(this.searchText)
                    )
                    this.statusFilter != "" &&
                        (this.filter_allreq = this.filter_allreq.filter(
                            item => item.req_status == this.statusFilter
                        ))
                    this.searchGroup != "" &&
                        (this.filter_allreq = this.filter_allreq.filter(
                            item => item.group_ID == this.searchGroup
                        ))
                    this.searchCohort != "" &&
                        (this.filter_allreq = this.filter_allreq.filter(
                            item => item.cohort_ID == this.searchCohort
                        ))
                },
                toggleSelectAll(event) {
                    const checked = event.target.checked;

                    // 這一行拉到 if 外面，兩邊都可以用
                    const idsOnPage = this.filter_allreq.map(item => item.req_ID);

                    if (checked) {
                        // 全選：把目前畫面上的 req_ID 全部加入（避免重複）
                        this.selectedReqIDs = Array.from(new Set([
                            ...this.selectedReqIDs,
                            ...idsOnPage
                        ]));
                    } else {
                        // 取消全選：把目前畫面上的 req_ID 從 selectedReqIDs 移除
                        this.selectedReqIDs = this.selectedReqIDs.filter(id => !idsOnPage.includes(id));
                    }
                },

                // ✅ 批次修改狀態（0=停用, 1=啟用）
                bulkChangeStatus(number) {
                    if (this.selectedReqIDs.length === 0) {
                        toast({
                            type: 'info',
                            title: '尚未選取資料'
                        });
                        return;
                    }
                    // 簡單一點：前端一個一個呼叫原本的 req_del
                    this.selectedReqIDs.forEach(id => {
                        $.post("../modules/requirement.php?do=req_del", {
                                ID: id,
                                number: number
                            })
                            .done(() => {
                                // 重新載入 + 清空勾選
                                this.get_req_ch();
                            })
                    })
                    this.selectedReqIDs = [];
                    toast({
                        type: 'success',
                        title: '批次更新完成'
                    });
                },

                // ✅ 清空目前所有勾選
                clearSelection() {
                    this.selectedReqIDs = [];
                },
                req_look_modal(i) {
                    this.form = i
                    $("#req_look_modal_ID").modal("show")

                },
            },
            mounted() {
                this.get_req_ch()
                this.get_cohortANDtype()
                this.filter_change_req()
                if (this.role_ID == 1 || this.role_ID == 2) {
                    this.select_group()
                } else if (this.role_ID == 4) {
                    this.select_team()
                }
            }
        }).mount("#req_app");
    }
</script>