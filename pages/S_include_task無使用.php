    <!-- 篩選區域 -->
    <div class="filter-section">
        <div class="filter-card">
            <div class="filter-header">
                <h3 style="font-weight:800">
                    分列方式
                </h3>
                <button class="btn-gantt" @click="task_modal_show">
                    新增便利貼
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
    <!-- 專題需求牆便利貼 心願便利貼：統一 board -->
    <div class="todo-board">
        <div class="todo-column" v-for="col in activeColumns" :key="col.key">
            <div class="todo-column-header">
                <h3 class="todo-column-title">{{ col.label }}</h3>
                <span class="todo-column-count">{{ col.tasks.length }}</span>
            </div>

            <div class="todo-column-body">
                <!-- 單張便利貼便利貼 -->
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

                        <!-- 起始日期 -->
                        <!-- 起始～截止日期 -->
                        <span class="todo-note-date"
                            v-if="(task.task_start_d && task.task_start_d!='0000-00-00 00:00:00') 
                                || (task.task_end_d && task.task_end_d!='0000-00-00 00:00:00')">

                            <span v-if="!task.task_end_d || task.task_end_d=='0000-00-00 00:00:00'">起始：</span>
                            <span v-if="!task.task_start_d || task.task_start_d=='0000-00-00 00:00:00'">截止：</span>

                            <span v-if="task.task_start_d && task.task_start_d!='0000-00-00 00:00:00'">
                                {{ task.task_start_d }}
                            </span>

                            <span v-if="task.task_start_d && task.task_end_d 
                                && task.task_start_d!='0000-00-00 00:00:00' 
                                && task.task_end_d!='0000-00-00 00:00:00'">
                                至
                            </span>

                            <span v-if="task.task_end_d && task.task_end_d!='0000-00-00 00:00:00'">
                                {{ task.task_end_d }}
                            </span>
                        </span>

                        <!-- 狀態 + 負責人 -->
                        <span class="req-count-tag"
                            :style="task.task_status === 1 ? 'background:#F8BF63' : (task.task_status === 3 ? 'background:#CAFCBB' : '')">
                            #&ensp;{{ statusChipText(task) }}
                        </span>
                    </div>
                </div>
                <!-- 沒便利貼的提示 -->
                <div class="todo-empty" v-if="!col.tasks.length">
                    尚未建立便利貼～
                </div>
            </div>
        </div>
    </div>


    <teleport to="body">
        <!-- 便利貼task modal -->
        <div class="modal fade" id="task_modal" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="mb-0">{{ form.id ? '編輯便利貼' : '新增便利貼' }}</h2>
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
                                            <div class="input-group" role="group" aria-label="Basic radio toggle button group">
                                                <span class="input-group-text"><b>對應預期成果</b></span>
                                                <select class="form-select" v-model="form.req_ID">
                                                    <option v-for="i in all_exresultdata" :value="i.rd_ID">{{i.rd_title}}
                                                    </option>
                                                </select>

                                                <select class="form-select" v-model="form.req_ID" v-if="form.req_ID=='miles'">
                                                    <!-- miles options -->
                                                </select>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text"><b>便利貼標題</b></span>
                                                <input type="text" v-model="form.title" class="form-control" id="title" maxlength="18">
                                            </div>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <div class="input-group range-group">
                                                <span class="input-group-text"><b>誰的便利貼</b></span>
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
                                        <span class="input-group-text"><b>便利貼說明</b></span>
                                        <textarea class="form-control" rows="4" style="resize:none;" v-model="form.desc"></textarea>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>起始日</b></span>
                                        <input type="datetime-local" class="form-control" v-model="form.start_d">
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>截止日</b></span>
                                        <input type="datetime-local" class="form-control" v-model="form.end_d">
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

                                    <!-- 起始日期 -->
                                    <span class="todo-note-date" v-if="form.start_d || form.end_d">
                                        <span v-if="!form.end_d">起始：</span>
                                        <span v-if="!form.start_d">截止：</span>
                                        <span v-if="form.start_d">{{ form.start_d }}</span>
                                        <span v-if="form.start_d && form.end_d">至</span>
                                        <span v-if="form.end_d">{{ form.end_d }}</span>
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

        <!-- 查看便利貼 look task modal -->
        <div class="modal fade" id="task_look_modal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <!-- 頂端標題列 -->
                    <div class="modal-header">
                        <h2>便利貼詳細資料</h2>
                        <button data-bs-dismiss="modal"
                            style="background:none;border:none;font-size:28px;cursor:pointer;color:#999;line-height:1;"
                            class="ms-auto">&times;</button>
                    </div>

                    <!-- 內容卡片：沿用 req-detail-card 樣式 -->
                    <div class="modal-body req-detail-card">
                        <!-- 標題 + 狀態 -->
                        <div class="req-detail-header">
                            <h3 class="req-detail-title">{{ now_task.task_title }}</h3>

                            <!-- 便利貼狀態標籤 -->
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
                                    尚未有人接下這個便利貼
                                </span>
                                <span v-else>
                                    {{
                                        now_task.done_name+(now_task.task_status!=0?(now_task.task_done_d?"　"+now_task.task_done_d:'')+(now_task.task_status==1?'　接下便利貼':'　完成便利貼'):'')}}
                                </span>
                            </p>
                        </div>

                        <!-- 起迄時間 -->
                        <div class="req-detail-section">
                            <label class="req-detail-label">起始/截止時間：</label>
                            <p class="req-detail-text">
                                <span
                                    v-if="now_task.task_start_d && now_task.task_start_d !== '0000-00-00 00:00:00'">
                                    起：{{ now_task.task_start_d }}
                                </span>
                                <br v-if="(now_task.task_start_d && now_task.task_start_d !== '0000-00-00 00:00:00') 
            && (now_task.task_end_d && now_task.task_end_d !== '0000-00-00 00:00:00')">
                                <span v-if="now_task.task_end_d && now_task.task_end_d !== '0000-00-00 00:00:00'">
                                    迄：{{ now_task.task_end_d }}
                                </span>
                                <span v-if="(!now_task.task_start_d || now_task.task_start_d === '0000-00-00 00:00:00')
            && (!now_task.task_end_d || now_task.task_end_d === '0000-00-00 00:00:00')">
                                    無設定起迄時間
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

    <!-- 下面是modal 下面是modal 下面是modal 下面是modal 下面是modal 下面是modal 下面是modal 下面是modal 下面是modal 下面是modal 下面是modal -->
    <teleport to="body">
        <!-- 便利貼task modal -->
        <div class="modal fade" id="task_modal" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>{{form.id?'編輯便利貼':'新增便利貼'}}</h2>
                        <i class="fa-solid fa-square-xmark ms-auto" style="font-size: 24px; cursor:pointer;"
                            @click="task_modal_close"></i>
                    </div>
                    <div class="modal-body">
                        <table>
                            <tr>
                                <td>
                                    <div class="input-group" role="group" aria-label="Basic radio toggle button group">
                                        <span class="input-group-text"><b>連結需求或里程碑：</b></span>
                                        <select class="form-select" v-model="form.req_ID">
                                            <option value=null>不連結</option>
                                            <option value="req">基本需求</option>
                                            <option value="miles">里程碑</option>
                                        </select>
                                        <select class="form-select" v-model="form.req_ID" v-if="form.req_ID=='req'">
                                            <option :value="i.req_ID" v-for="i in all_requirement">{{i.req_title}}
                                            </option>
                                        </select>
                                        <select class="form-select" v-model="form.req_ID" v-if="form.req_ID=='miles'">
                                        </select>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>便利貼標題</b></span>
                                        <input type="text" v-model="form.title" class="form-control" id="title">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group range-group">
                                        <span class="input-group-text"><b>誰的便利貼</b></span>
                                        <select class="form-select" v-model="form.who_task">
                                            <option value=null>暫不部屬</option>
                                            <option :value="i.team_u_ID"
                                                v-for="i in (all_teammumber.filter(c => c.role_ID === 6))">{{i.u_name}}
                                            </option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group range-group">
                                        <span
                                            class="input-group-text"><b>標籤({{form.priority==1?'一般':form.priority==2?'重要':'緊急'}})</b></span>
                                        <input type="range" max="4" min="1" step="1" class="form-range"
                                            v-model="form.priority">
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
                                        <span class="input-group-text"><b>便利貼說明</b></span>
                                        <textarea class="form-control" rows="4" style="resize: none;"
                                            v-model="form.desc"></textarea>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>起始日</b></span>
                                        <input type="datetime-local" class="form-control" v-model="form.start_d">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="input-group">
                                        <span class="input-group-text"><b>截止日</b></span>
                                        <input type="datetime-local" class="form-control" v-model="form.end_d">
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" @click="task_submit('edit')" v-if="form.id">送出編輯</button>
                        <button class="btn btn-primary" @click="task_submit('new')" v-else>確定新增</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 查看便利貼 look task modal -->
        <div class="modal fade" id="task_look_modal" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog">
                <div class="modal-content">
                    <!-- 頂端標題列 -->
                    <div class="modal-header">
                        <h2>便利貼詳細資料</h2>
                        <i class="fa-solid fa-square-xmark ms-auto" style="font-size: 24px; cursor:pointer;"
                            data-bs-dismiss="modal"></i>
                    </div>

                    <!-- 內容卡片：沿用 req-detail-card 樣式 -->
                    <div class="modal-body req-detail-card">
                        <!-- 標題 + 狀態 -->
                        <div class="req-detail-header">
                            <h3 class="req-detail-title">{{ now_task.task_title }}</h3>

                            <!-- 便利貼狀態標籤 -->
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
                                {{ now_task.task_desc || '（尚未填寫說明）' }}
                            </p>
                        </div>

                        <!-- 負責人 -->
                        <div class="req-detail-section">
                            <label class="req-detail-label">負責人：</label>
                            <p class="req-detail-text">
                                <span v-if="now_task.task_status==0">
                                    尚未有人接下這個便利貼
                                </span>
                                <span v-else>
                                    {{
                                now_task.done_name+(now_task.task_status!=0?(now_task.task_done_d?"　"+now_task.task_done_d:'')+(now_task.task_status==1?'　接下便利貼':'　完成便利貼'):'')}}
                                </span>
                            </p>
                        </div>

                        <!-- 起迄時間 -->
                        <div class="req-detail-section">
                            <label class="req-detail-label">起始/截止時間：</label>
                            <p class="req-detail-text">
                                <span v-if="now_task.task_start_d!=null">
                                    起：{{ now_task.task_start_d }}
                                </span>
                                <br v-if="now_task.task_start_d!=null && now_task.task_end_d!=null">
                                <span v-if="now_task.task_end_d!=null">
                                    迄：{{ now_task.task_end_d }}
                                </span>
                                <span v-if="now_task.task_start_d==null && now_task.task_end_d==null">
                                    尚未設定起迄時間
                                </span>
                            </p>
                        </div>

                        <!-- 標籤 -->
                        <div class="req-detail-section">
                            <label class="req-detail-label">標籤：</label>
                            <p class="req-detail-text">
                                <span class="req-count-tag" v-if="now_task.task_priority" :style="'background:' + (now_task.task_priority==1?'#FFE98A'
                                            :now_task.task_priority==2?'#acd6f8ff'
                                            :now_task.task_priority==3?'#FF955C'
                                            :'#ff6c6cc2')">
                                    #
                                    {{ now_task.task_priority==1 ? '一般'
                                : now_task.task_priority==2 ? '重要'
                                : '緊急' }}
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
                        <button class="btn btn-warning" v-if="now_task.task_status==1 && u_ID==now_task.task_done_u_ID"
                            style="margin-right: 14px;" @click="take_task(0)">
                            放棄接下
                        </button>
                        <button class="btn btn-primary" @click="take_task(3)" v-if="now_task.task_status==1 && u_ID==now_task.task_done_u_ID">
                            完成
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </teleport>