    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="css/expected_outcome.css?v=<?= time() ?>">
    <style>
        .ai-loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.35);
            z-index: 999999;

            display: flex;
            justify-content: center;
            /* 水平置中 */
            align-items: center;
            /* 垂直置中 */
        }

        .ai-loading.show {
            display: flex;
        }

        .ai-loading-box {
            background: #fff;
            padding: 30px 40px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .ai-loading-text {
            margin-top: 12px;
            font-size: 18px;
            font-weight: 600;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #ddd;
            border-top: 5px solid #0d6efd;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>
    <?php session_start(); ?>
    <div id="outcome_app">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fa-solid fa-layer-group me-2" style="color: #ffc107;"></i>專題預期成果
            </h1>
            <div>
                <button class="btn btn-primary" type="button" @click="go_show_all">查看AI進度評分</button>&emsp;
                <button class="btn btn-primary" type="button" @click="exresultdata_modal_show">匯出當前預期成果</button>
            </div>
        </div>
        <div class="team-switch-container">
            <div class="team-switch-buttons">
                <button
                    class="team-btn btn btn-primary"
                    :class="{ active: now_team_ID === i.team_ID }"
                    v-for="i in all_team_ID"
                    @click="changeTab(activeTab, i.team_ID)">
                    <i class="fa-solid fa-users"></i>
                    {{ i.team_project_name }}
                </button>
            </div>
        </div>
        <!-- ✅ 最低專題要求自我檢查 -->
        <section class="outcome-section">
            <div class="outcome-head">
                <div class="title">
                    <div class="icon-badge">✓</div>
                    <div style="min-width:0;">
                        <h3>{{now_group.name}}：專題要求自我檢查</h3>
                        <p>專題必需完成之事項，請完成後盡速回報給指導老師。</p>
                    </div>
                </div>
            </div>
            <div class="outcome-table-wrap">
                <table class="outcome-table fixed">
                    <colgroup>
                        <col style="width: 65px;">
                        <col style="width: 320px;">
                        <col>
                        <col style="width: 180px;">
                        <col style="width: 165px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>完成</th>
                            <th>標題</th>
                            <th>回報內容</th>
                            <th>回報紀錄</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(i, key) in all_requirement" :key="i.req_ID">
                            <td>
                                <div class="form-check user-select-checkbox">
                                    <input
                                        class="form-check-input user-checkbox"
                                        type="checkbox"
                                        disabled
                                        :class="{ 'is-readonly': editReqId !== i.req_ID }"
                                        :checked="getRpDone(i.req_ID) === 3"
                                        @change="onDoneChange(i.req_ID, $event)" />
                                </div>
                            </td>

                            <td>
                                <!-- 顯示模式 -->
                                <strong>{{ i.req_title }}</strong>
                            </td>

                            <td v-if="all_rp.find(x => x.req_ID === i.req_ID)?.rp_comment || editReqId == i.req_ID">
                                <div v-if="hasCount(i)" class="qwrap">
                                    <!-- 顯示模式 -->
                                    <div v-if="editReqId !== i.req_ID" class="qpill">
                                        <span class="qpair">
                                            <span class="qlabel">預期：</span>
                                            <span class="qvalue">{{ i.req_count[0] }}{{ i.req_count[1] }}</span>
                                        </span>

                                        <span class="qsep"></span>

                                        <span class="qpair">
                                            <span class="qlabel">回報：</span>
                                            <span class="qvalue">{{ all_rp.find(x => x.req_ID === i.req_ID)?.rp_count + i.req_count[1] }}</span>
                                        </span>
                                    </div>
                                    <!-- 編輯模式 -->
                                    <div v-else class="qpill">
                                        <span class="qpair">
                                            <span class="qlabel">預期：</span>
                                            <span class="qvalue">{{ i.req_count[0] }}{{ i.req_count[1] }}</span>
                                        </span>
                                        <span class="qsep"></span>
                                        <span class="qpair">
                                            <span class="qlabel">回報：</span>
                                            <input
                                                class="q-input"
                                                type="number"
                                                min="0"
                                                step="1"
                                                v-model.number="editRowReq.rp_count"
                                                placeholder="" />
                                            <span class="qunit">{{ i.req_count[1] }}</span>
                                        </span>
                                    </div>

                                    <!-- 量化 -->
                                    <div class="qtext">
                                        <!-- 顯示模式 -->
                                        <strong v-if="editReqId !== i.req_ID" class="text-muted">
                                            {{ all_rp.find(x => x.req_ID === i.req_ID)?.rp_comment}}
                                        </strong>

                                        <!-- 編輯模式 -->
                                        <textarea
                                            v-else
                                            class="inline-input"
                                            v-model.trim="editRowReq.rp_comment"
                                            placeholder="請輸入回報內容"></textarea>
                                    </div>
                                </div>

                                <!-- 質化 -->
                                <template v-else>
                                    <!-- 顯示模式 -->
                                    <strong class="text-muted" v-if="editReqId !== i.req_ID">
                                        {{ all_rp.find(x => x.req_ID === i.req_ID)?.rp_comment}}
                                    </strong>
                                    <!-- 編輯模式 -->
                                    <textarea
                                        v-else
                                        style="width:100%;"
                                        class="inline-input"
                                        v-model.trim="editRowReq.rp_comment"
                                        placeholder="請輸入回報內容"></textarea>
                                </template>
                            </td>
                            <td v-else style="color: #adb5bd;">
                                尚未回報
                            </td>
                            <td>
                                <!-- 最後回報 -->
                                <div>
                                    {{ getRpByReqId(i.req_ID)?.rp_completed_d || '' }}
                                    {{ getRpByReqId(i.req_ID)?.u_name || '' }}
                                    <span
                                        :style="
                                            all_requirement.find(x => x.req_ID == i.req_ID)?.status === 0
                                            ? (getRpByReqId(i.req_ID)?.u_name ? 'color:#0d6efd;' :'')
                                            : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 1
                                            ? 'color:#ffc107;'
                                            : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 2
                                            ? 'color:#dc3545;'
                                            : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 3
                                            ? 'color:#198754;'
                                            : ''">
                                        {{ (all_requirement.find(x => x.req_ID == i.req_ID)?.status === 0 && getRpByReqId(i.req_ID)?.u_name)
                                            ? '(待老師查看)'
                                            : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 1
                                            ? '(待完整回報)'
                                            : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 2
                                            ? '(請修改回報)'
                                            : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 3
                                            ? '(已通過)'
                                            : ''}}
                                    </span>
                                </div>

                                <!-- ✅ 審核時間 -->
                                <div v-if="getRpApproved(i.req_ID)" class="text-muted" style="font-size:12px; margin-top:4px;">審核：{{ getRpApproved(i.req_ID) }}
                                </div>

                                <!-- ✅ 說明 / 備註 -->
                                <div v-if="getRpRemark(i.req_ID)" class="text-muted" style="font-size:12px; margin-top:2px; white-space:pre-wrap;">老師：{{ getRpRemark(i.req_ID) }}
                                </div>
                            </td>
                            <td>
                                <div class="actions">
                                    <!-- 顯示模式：編輯 -->
                                    <button
                                        v-if="editReqId !== i.req_ID"
                                        class="btn-soft btn-primary"
                                        type="button"
                                        @click="startEdit(i)">
                                        <i class="fa-solid fa-pen"></i>回報
                                    </button>

                                    <!-- 編輯模式：送出 -->
                                    <span v-else>
                                        <button
                                            style="margin-right:8px;"
                                            class="btn-soft  btn-primary"
                                            type="button"
                                            @click="cancelEdit()">
                                            <i class="fa-solid fa-xmark"></i>取消
                                        </button>
                                        <button
                                            class="btn-soft  btn-primary"
                                            type="button"
                                            @click="submitEdit(i)">
                                            <i class="fa-solid fa-paper-plane"></i>送出
                                        </button>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ✅ 專題預期成果 -->
        <section class="outcome-section">
            <div class="outcome-head">
                <div class="title">
                    <div class="icon-badge">★</div>
                    <div style="min-width:0;">
                        <h3>專題預期成果</h3>
                        <p>可用勾選標記完成，並支援編輯 / 刪除 (每次異動需用文字說明！)。</p>
                    </div>
                </div>
                <div class="outcome-tools">
                    <button class="btn-soft btn-primary" type="button" @click="openAllLogs(true)">
                        查看異動紀錄
                    </button>
                    <button class="btn-soft btn-primary" type="button" @click="openAddExpectedRow">
                        <i class="fa-solid fa-plus"></i>新增成果
                    </button>
                </div>
            </div>
            <div class="outcome-table-wrap">
                <table class="outcome-table">
                    <colgroup>
                        <col style="width: 65px;">
                        <col style="width: 280px;">
                        <col>
                        <col style="width: 200px;">
                        <col style="width: 120px;">
                        <col style="width: 165px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>完成</th>
                            <th>標題</th>
                            <th>內容</th>
                            <th>最後編輯時間/者</th>
                            <th>負責人</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- ✅ 新增成果列：只有按下「新增成果」才出現 -->
                        <tr v-if="showAddExpectedRow">
                            <td>
                                <div class="form-check user-select-checkbox">
                                    <input class="form-check-input user-checkbox" type="checkbox" disabled>
                                </div>
                            </td>
                            <!-- 標題 -->
                            <td>
                                <input
                                    type="text"
                                    class="inline-input"
                                    placeholder="請輸入標題"
                                    v-model.trim="editRow_Expected.title">
                            </td>
                            <!-- 內容 -->
                            <td>
                                <textarea
                                    style="width:100%;"
                                    class="inline-input"
                                    placeholder="請輸入內容"
                                    rows="3"
                                    v-model.trim="editRow_Expected.value"></textarea>
                            </td>
                            <!-- 最後編輯時間/者 -->
                            <td>-</td>
                            <!-- 負責人 -->
                            <td>
                                <select class="inline-input" v-model="editRow_Expected.CEO">
                                    <option value="">未定</option>
                                    <option
                                        v-for="(m, idx) in all_teammumber.filter(x => x.role_ID == 6)"
                                        :key="'ceo_'+idx"
                                        :value="m.team_u_ID">
                                        {{m.u_name}}
                                    </option>
                                </select>
                            </td>
                            <!-- 操作 -->
                            <td>
                                <div class="actions">
                                    <button
                                        class="btn-soft btn-primary"
                                        type="button"
                                        @click="cancelAddExpected()">
                                        <i class="fa-solid fa-xmark"></i>取消
                                    </button>
                                    <button
                                        class="btn-soft btn-primary"
                                        type="button"
                                        @click="submitAddExpected()">
                                        <i class="fa-solid fa-plus"></i>新增
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="(i, index) in all_expected" :key="i.rd_ID">
                            <td>
                                <div class="form-check user-select-checkbox">
                                    <input
                                        class="form-check-input user-checkbox"
                                        type="checkbox"
                                        :disabled="editExpectedId !== i.rd_ID"
                                        :class="{ 'is-readonly': editExpectedId !== i.rd_ID }"
                                        :checked="Number(get_expectedDone(i)) === 3"
                                        @change="onExpectedDoneChange(i, $event)">
                                </div>
                            </td>

                            <!-- 標題 -->
                            <td>
                                <!-- 顯示模式 -->
                                <strong v-if="editExpectedId !== i.rd_ID">{{ i.rd_title }}</strong>
                                <!-- 編輯模式 -->
                                <input
                                    v-else
                                    type="text"
                                    class="inline-input"
                                    placeholder="請輸入標題"
                                    v-model.trim="editRowExpected.rd_title">
                            </td>

                            <!-- 內容 -->
                            <td class="text-muted">
                                <!-- 顯示模式 -->
                                <strong v-if="editExpectedId !== i.rd_ID">{{ i.rd_content }}</strong>
                                <!-- 編輯模式 -->
                                <textarea
                                    v-else
                                    class="inline-input"
                                    placeholder="請輸入內容"
                                    rows="3"
                                    v-model.trim="editRowExpected.rd_content"></textarea>
                            </td>
                            <!-- 最後編輯時間/者（照你原本顯示方式） -->
                            <td>
                                <button
                                    type="button"
                                    class="last-edit-btn"
                                    @click="epxected_openLog(i)">
                                    <div class="leb-time">{{ i.rd_finish_d || '-' }}</div>
                                    <div class="leb-user">
                                        <span>{{ i.rd_u_name_b ?? '' }}</span>
                                        <span class="leb-hint-inline">（點擊查看異動紀錄）</span>
                                    </div>
                                </button>
                            </td>

                            <!-- 負責人 -->
                            <td style="text-align:center">
                                <!-- 顯示模式 -->
                                <span v-if="editExpectedId !== i.rd_ID">{{ i.rd_u_name_a ?? "未定" }}</span>

                                <!-- 編輯模式 -->

                                <select v-else class="inline-input" v-model="editRowExpected.rd_u_ID_a">
                                    <option value="">未定</option>
                                    <option
                                        v-for="(m, idx) in all_teammumber.filter(x => x.role_ID == 6)"
                                        :key="'ceo_edit_'+idx"
                                        :value="m.team_u_ID">
                                        {{ m.u_name}}
                                    </option>
                                </select>
                            </td>
                            <!-- 操作 -->
                            <td>
                                <div class="actions">
                                    <!-- 顯示模式：編輯 -->
                                    <button
                                        v-if="editExpectedId !== i.rd_ID"
                                        class="btn-soft btn-primary"
                                        type="button"
                                        @click="startEditExpected(i)">
                                        <i class="fa-solid fa-pen"></i>編輯
                                    </button>

                                    <!-- 編輯模式：取消/送出 -->
                                    <span v-else>
                                        <button
                                            style="margin-right:8px;"
                                            class="btn-soft btn-primary"
                                            type="button"
                                            @click="cancelEditExpected()">
                                            <i class="fa-solid fa-xmark"></i>取消
                                        </button>
                                        <button
                                            class="btn-soft btn-primary"
                                            type="button"
                                            @click="submitEditExpected(i,1)">
                                            <i class="fa-solid fa-paper-plane"></i>送出
                                        </button>
                                    </span>

                                    <!-- 刪除：不在編輯中才顯示（避免混亂） -->
                                    <button
                                        v-if="editExpectedId !== i.rd_ID"
                                        class="btn-soft danger btn-primary"
                                        type="button"
                                        @click="deleteExpected(i)">
                                        <i class="fa-solid fa-trash"></i>刪除
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <div class="diff-page">
            <div class="diff-card" v-if="curEx">

                <div class="diff-toolbar">
                    <div class="diff-title">
                        <div class="badge">≠</div>
                        <div style="min-width:0;">
                            <h2>檔案差異檢視</h2>
                            <p class="diff-subtitle">透過上一頁/下一頁查看預期成果變化吧！</p>
                        </div>
                    </div>

                    <div class="diff-actions">
                        <button class="btn-nav primary" type="button" @click="prevPage" :disabled="!canPrev">
                            <span class="icon">&lt;</span>
                            <span>上一頁</span>
                        </button>
                        <button class="btn-nav primary" type="button" @click="nextPage" :disabled="!canNext">
                            <span>下一頁</span>
                            <span class="icon">&gt;</span>
                        </button>
                    </div>
                </div>

                <div class="diff-wrap">
                    <div class="diff-wrap" v-if="curPair">

                        <!-- Left：上一期 -->
                        <div class="panel" id="panelL">
                            <div class="panel-header">
                                <div class="month">
                                    {{ curPair.L.sfd_name }}
                                    <button class="btn-soft ms-2 btn-primary" type="button" @click="exportDiffPdf('L')">下載</button>
                                </div>
                                <div class="small">統計至 {{ curPair.L.sfd_submit_d }} 為止。</div>
                            </div>

                            <div class="panel-body">
                                <div class="pdf-preview-wrap">
                                    <div class="pdf-preview-page">

                                        <div class="pdf-head">
                                            <div>專題名稱：{{ now_group.team_project_name || '-' }}</div>
                                            <div>指導老師：{{ teacherName }}</div>
                                            <div>
                                                成員：
                                                <span v-for="(m, idx) in members" :key="'L_pm_'+idx">
                                                    {{ m.u_name }}<span v-if="idx !== members.length-1">、</span>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="pdf-section-title">最低專題要求自我檢查</div>
                                        <table class="pdf-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:50px;">完成</th>
                                                    <th>標題</th>
                                                    <th style="width:200px;">回報</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="i in getReqForSide('L')" :key="'L_req_'+i.req_ID">
                                                    <td style="text-align:center;">{{ getRpDone(i.req_ID) === 3 ? '是' : '' }}</td>
                                                    <td>{{ i.req_title }}</td>
                                                    <td class="pdf-cell-break">
                                                        <template v-if="hasCount(i)">
                                                            預期：{{ i.req_count?.[0] }}{{ i.req_count?.[1] }}　
                                                            回報：{{ (getRpForSide(i.req_ID)?.rp_count ?? '') }}{{ i.req_count?.[1] }}
                                                            <div v-if="getRpForSide(i.req_ID)?.rp_comment">
                                                                {{ getRpForSide(i.req_ID)?.rp_comment }}
                                                            </div>
                                                        </template>
                                                        <template v-else>
                                                            {{ getRpForSide(i.req_ID)?.rp_comment || '' }}
                                                        </template>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <div class="pdf-section-title" style="margin-top:18px;">專題預期成果</div>
                                        <table class="pdf-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:50px;">完成</th>
                                                    <th>標題</th>
                                                    <th>內容</th>
                                                    <th style="width:70px;">負責人</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="e in getExpectedForSide('L')" :key="'L_e_'+e.rd_ID">
                                                    <td style="text-align:center;">{{ Number(e.rd_status) === 3 ? '是' : '' }}</td>
                                                    <td>{{ e.rd_title }}</td>
                                                    <td class="pdf-cell-break">{{ e.rd_content }}</td>
                                                    <td style="text-align:center;">{{ e.rd_u_name_a ?? '未定' }}</td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <div class="pdf-foot">
                                            以上資料統計至：{{ curPair.L.sfd_submit_d }} 23:59:59 為止。
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right：本期 -->
                        <div class="panel" id="panelR">
                            <div class="panel-header">
                                <div class="month">
                                    {{ curPair.R.sfd_name }}
                                    <button class="btn-soft ms-2 btn-primary" type="button" @click="exportDiffPdf('R')">下載</button>
                                </div>
                                <div class="small">統計至 {{ curPair.R.sfd_submit_d }} 為止。</div>
                            </div>

                            <div class="panel-body">
                                <div class="pdf-preview-wrap">
                                    <div class="pdf-preview-page">

                                        <div class="pdf-head">
                                            <div>專題名稱：{{ now_group.team_project_name || '-' }}</div>
                                            <div>指導老師：{{ teacherName }}</div>
                                            <div>
                                                成員：
                                                <span v-for="(m, idx) in members" :key="'R_pm_'+idx">
                                                    {{ m.u_name }}<span v-if="idx !== members.length-1">、</span>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="pdf-section-title">最低專題要求自我檢查</div>
                                        <table class="pdf-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:50px;">完成</th>
                                                    <th>標題</th>
                                                    <th style="width:200px;">回報</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="i in getReqForSide('R')" :key="'R_req_'+i.req_ID">
                                                    <td style="text-align:center;">{{ getRpDone(i.req_ID) === 3 ? '是' : '' }}</td>
                                                    <td>{{ i.req_title }}</td>
                                                    <td class="pdf-cell-break">
                                                        <template v-if="hasCount(i)">
                                                            預期：{{ i.req_count?.[0] }}{{ i.req_count?.[1] }}　
                                                            回報：{{ (getRpForSide(i.req_ID)?.rp_count ?? '') }}{{ i.req_count?.[1] }}
                                                            <div v-if="getRpForSide(i.req_ID)?.rp_comment">
                                                                {{ getRpForSide(i.req_ID)?.rp_comment }}
                                                            </div>
                                                        </template>
                                                        <template v-else>
                                                            {{ getRpForSide(i.req_ID)?.rp_comment || '' }}
                                                        </template>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <div class="pdf-section-title" style="margin-top:18px;">專題預期成果</div>
                                        <table class="pdf-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:50px;">完成</th>
                                                    <th>標題</th>
                                                    <th>內容</th>
                                                    <th style="width:70px;">負責人</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="e in getExpectedForSide('R')" :key="'R_e_'+e.rd_ID">
                                                    <td style="text-align:center;">{{ Number(e.rd_status) === 3 ? '是' : '' }}</td>
                                                    <td>{{ e.rd_title }}</td>
                                                    <td class="pdf-cell-break">{{ e.rd_content }}</td>
                                                    <td style="text-align:center;">{{ e.rd_u_name_a ?? '未定' }}</td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <div class="pdf-foot">
                                            以上資料統計至：{{ curPair.R.sfd_submit_d }} 23:59:59 為止。
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>
            </div>

            <div v-else class="empty-hint">（目前沒有任何資料）</div>
            <teleport to="body">
                <div class="modal fade" id="AI_modal" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered modal-xl">
                        <div class="modal-content">
                            <div class="modal-body">
                                <h3 class="d-flex align-items-center">
                                    <span>AI進度評分紀錄</span>
                                    <button data-bs-dismiss="modal"
                                        style="background:none;border:none;font-size:28px;cursor:pointer;color:#999;line-height:1;"
                                        class="ms-auto">&times;</button>
                                </h3>

                                <div class="text-muted" style="font-size:12px;">
                                    此為AI進度評分，僅供參考。AI會根據專題要求與預期成果自動生成，可能不完全準確，請以實際專題成果為主。
                                </div>

                                <div v-if="filtered_AI.length" style="max-height:60vh; overflow:auto; margin-top:10px;">
                                    <div class="outcome-table-wrap modal-table-wrap">
                                        <table class="outcome-table fixed">
                                            <colgroup>
                                                <col style="width:100px;">
                                                <col style="width:130px;">
                                                <col style="width:70px;">
                                                <col style="width:70px;">
                                                <col>
                                            </colgroup>
                                            <thead>
                                                <tr>
                                                    <th>紀錄標題</th>
                                                    <th>截止日</th>
                                                    <th>分數</th>
                                                    <th>排名</th>
                                                    <th style="text-align:left;">建議</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="r in filtered_AI" :key="r.sfd_ID">
                                                    <td>{{ r.sfd_name }}</td>
                                                    <td>{{ r.sfd_submit_d }}</td>
                                                    <td>{{ r.sfd_score ?? '—' }}</td>
                                                    <td>{{ r.sfd_rank ?? '—' }}{{"/"}}{{r.total_teams ?? ''}}</td>
                                                    <td class="text-start" style="white-space:pre-wrap;">
                                                        {{ (r.sfd_suggest && r.sfd_suggest.trim()) ? r.sfd_suggest : '（尚無建議）' }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div v-else class="text-center text-muted py-4">
                                    目前沒有進度評分紀錄
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="Expected_modal" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-body">
                                <h3 class="d-flex align-items-center">
                                    <span>請填寫異動緣由：</span>
                                    <i class="fa-solid fa-square-xmark ms-auto"
                                        style="font-size: 24px; cursor:pointer;"
                                        @click="Expected_modal_close"></i>
                                </h3>
                                <textarea class="inline-input" v-model="editRowExpected.cr_reason"></textarea>
                                <button class="btn-soft btn-primary" style="margin-top: 10px;" @click="submitEditExpected('',2)">確定送出</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="del_Expected_modal" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-body">
                                <h3 class="d-flex align-items-center">
                                    <span>請填寫刪除({{editRowExpected.rd_title}})緣由：</span>
                                    <i class="fa-solid fa-square-xmark ms-auto"
                                        style="font-size: 24px; cursor:pointer;"
                                        @click="Expected_modal_close"></i>
                                </h3>

                                <textarea class="inline-input" v-model="editRowExpected.cr_reason"></textarea>
                                <button class="btn-soft danger btn-primary" style="margin-top: 10px;" @click="deleteExpected('')">確定刪除</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="changerecordsdata_modal" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div class="d-flex align-items-center w-100 m-0">
                                    <h3>異動紀錄</h3>
                                    <span class="ms-3">項目:</span>
                                    <select class="ms-2" v-model="filter_log.rd_ID">
                                        <option value="">全部</option>
                                        <option v-for="i in all_expected" :key="i.rd_ID" :value="String(i.rd_ID)">
                                            {{ i.rd_ID }}({{ i.rd_title }})
                                        </option>
                                    </select>
                                    、內容關鍵字:
                                    <input type="text" class="ms-2" v-model.trim="filter_log.value_key_word">
                                    、負責人：
                                    <select v-model="filter_log.u_ID">
                                        <option value="all">全部</option>
                                        <option value="">未定</option>
                                        <option
                                            v-for="(m, idx) in all_teammumber.filter(x => x.role_ID == 6)"
                                            :key="'ceo_'+idx"
                                            :value="m.team_u_ID">
                                            {{m.u_name}}
                                        </option>
                                    </select>
                                    、異動緣由關鍵字：
                                    <input type="text" class="ms-2" v-model.trim="filter_log.chager_key_word">
                                    <!-- 👇 關鍵：只把 X 推到最右 -->
                                    <i class="fa-solid fa-square-xmark ms-auto"
                                        style="font-size: 24px; cursor:pointer;"
                                        @click="epxected_closeLog"></i>
                                </div>
                            </div>
                            <div class="modal-body" style="max-height: 70vh;overflow-y: auto;   /* 超出就出現垂直捲軸 */">
                                <div class="outcome-table-wrap modal-table-wrap">
                                    <table class="outcome-table fixed">
                                        <colgroup>
                                            <col style="width:110px;">
                                            <col style="width:90px;">
                                            <col>
                                            <col>
                                            <col>
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th>異動時間</th>
                                                <th>類型</th>
                                                <th>原始資料</th>
                                                <th>更新資料</th>
                                                <th>異動緣由</th>
                                            </tr>

                                        </thead>
                                        <tbody>
                                            <tr v-for="(log, idx) in filtered_logs" :key="'log_'+idx">
                                                <td>{{ log.cr_update_d }}</td>
                                                <td>{{ log.cr_u_name + log.cr_type }}</td>
                                                <td class="text-start log-cell">
                                                    <ul class="m-0 ps-3" v-if="parseArr(log.cr_record).length">
                                                        <li>標題：{{parseArr(log.cr_record).slice(0, 1)[0]}}</li>
                                                        <li>內容：{{parseArr(log.cr_record).slice(1, 2)[0]}}</li>
                                                        <li>負責：{{ log.cr_record_name }}</li>
                                                    </ul>
                                                    <span v-else>{{ log.cr_record }}</span>
                                                </td>
                                                <td class="text-start log-cell">
                                                    <ul class="m-0 ps-3" v-if="parseArr(log.cr_update_data).length">
                                                        <li :class="diffClass(getTitle(log, 'u'), getTitle(log, 'r'))">
                                                            標題：{{ getTitle(log, 'u') }}
                                                        </li>
                                                        <li :class="diffClass(getContent(log, 'u'), getContent(log, 'r'))">
                                                            內容：{{ getContent(log, 'u') }}
                                                        </li>
                                                        <li :class="diffClass(getOwner(log, 'u'), getOwner(log, 'r'))">
                                                            負責：{{ getOwner(log, 'u') }}
                                                        </li>
                                                    </ul>
                                                    <span v-else :class="diffClass(String(log.cr_update_data||''), String(log.cr_record||''))">
                                                        {{ log.cr_update_data }}
                                                    </span>
                                                </td>
                                                <td>{{ log.cr_reason }}</td>
                                            </tr>

                                            <!-- ✅ 沒有任何資料時顯示 -->
                                            <tr v-if="!filtered_logs || filtered_logs.length === 0">
                                                <td colspan="5" class="text-center text-muted py-4">
                                                    暫無異動紀錄
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="exresultdata_modal" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-xl modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div class="d-flex align-items-center w-100 m-0">
                                    <h3 class="m-0">預覽文件</h3>
                                    <i class="fa-solid fa-square-xmark ms-auto"
                                        style="font-size: 24px; cursor:pointer;" data-bs-dismiss="modal"></i>
                                </div>
                            </div>

                            <!-- ✅ 預覽內容 -->
                            <div class="modal-body">
                                <div class="pdf-preview-wrap">
                                    <div class="pdf-preview-page">
                                        <!-- ✅ 這份就是預覽用（畫面顯示） -->
                                        <div class="pdf-head">
                                            <div>專題名稱：{{ now_group.team_project_name || '-' }}</div>
                                            <div>指導老師：{{ teacherName }}</div>
                                            <div>
                                                成員：
                                                <span v-for="(m, idx) in members" :key="'pm_'+idx">
                                                    {{ m.u_name }}<span v-if="idx !== members.length-1">、</span>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="pdf-section-title">最低專題要求自我檢查</div>
                                        <table class="pdf-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:50px;">完成</th>
                                                    <th style="width:280px;">標題</th>
                                                    <th>回報</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="i in all_requirement" :key="'pr_'+i.req_ID">
                                                    <td style="text-align:center;">{{ getRpDone(i.req_ID) === 3 ? '是' : '' }}</td>
                                                    <td>{{ i.req_title }}</td>
                                                    <td class="pdf-cell-break">
                                                        <template v-if="hasCount(i)">
                                                            預期：{{ i.req_count?.[0] }}{{ i.req_count?.[1] }}　
                                                            回報：{{ (getRpByReqId(i.req_ID)?.rp_count ?? '') }}{{ i.req_count?.[1] }}
                                                            <div v-if="getRpByReqId(i.req_ID)?.rp_comment">
                                                                {{ getRpByReqId(i.req_ID)?.rp_comment }}
                                                            </div>
                                                        </template>
                                                        <template v-else>
                                                            {{ getRpByReqId(i.req_ID)?.rp_comment || '' }}
                                                        </template>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <div class="pdf-section-title" style="margin-top:18px;">專題預期成果</div>
                                        <table class="pdf-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:50px;">完成</th>
                                                    <th style="width:260px;">標題</th>
                                                    <th>內容</th>
                                                    <th style="width:70px;">負責人</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="e in all_expected" :key="'pe_'+e.rd_ID">
                                                    <td style="text-align:center;">{{ Number(get_expectedDone(e)) === 3 ? '是' : '' }}</td>
                                                    <td>{{ e.rd_title }}</td>
                                                    <td class="pdf-cell-break">{{ e.rd_content }}</td>
                                                    <td style="text-align:center;">{{ e.rd_u_name_a ?? '未定' }}</td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <div class="pdf-foot">
                                            以上資料統計至{{ exportNow }}為止(匯出時間)。
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button class="btn btn-primary" type="button" @click="exportPdf">確定匯出</button>
                            </div>
                        </div>
                    </div>
                </div>
            </teleport>
            <div id="ai_loading" class="ai-loading">
                <div class="ai-loading-box">
                    <div class="spinner"></div>
                    <div class="ai-loading-text">AI評分中...請稍後</div>
                </div>
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
                if (window.outcomeVueApp && typeof window.outcomeVueApp.unmount === 'function') {
                    try {
                        window.outcomeVueApp.unmount();
                    } catch (e) {
                        console.warn('卸載 outcome app 時出錯:', e);
                    }
                }
                window.outcomeVueApp = null;

                if (!window.outcomeVueApp) {
                    window.outcomeVueApp = Vue.createApp({
                        data() {
                            return {
                                now_group: {
                                    ID: "",
                                    name: "",
                                    team_project_name: ""
                                },
                                all_requirement: [],
                                all_rp: [],
                                now_cohort_ID: null,
                                all_teammumber: [],
                                now_team_ID: null,
                                editReqId: null, // 最低專題需求目前正在編輯哪一列（req_ID）
                                editRowReq: {
                                    req_title: '',
                                    rp_comment: '',
                                    rp_done: 0
                                },
                                showAddExpectedRow: false,
                                editRow_Expected: {
                                    title: "",
                                    value: "",
                                    CEO: "",
                                },
                                all_expected: [],
                                editExpectedId: null, // 預期成果目前正在編輯哪一列（rd_ID）
                                editRowExpected: {
                                    rd_ID: null,
                                    rd_title: '',
                                    rd_content: '',
                                    rd_u_ID_a: '',
                                    rd_done: 0,
                                    cr_reason: ''
                                },
                                pendingExpectedPayload: null,
                                form: [],
                                filter_log: {
                                    rd_ID: "",
                                    value_key_word: "",
                                    chager_key_word: "",
                                    u_ID: "all"
                                },
                                filter_log_backup: null,
                                all_log: [],
                                exresultdata: [],

                                cursor: 0,
                                leftFileIdx: 0,
                                rightFileIdx: 0,
                                _addsfd_auto_done: false,

                                all_AI: [],
                            }
                        },

                        computed: {
                            filtered_logs() {
                                const kwValue = this.normalizeText(this.filter_log.value_key_word).toLowerCase();
                                const kwReason = this.normalizeText(this.filter_log.chager_key_word).toLowerCase();
                                const rdId = this.normalizeText(this.filter_log.rd_ID);

                                // ✅ 負責人篩選值：all / ''(未定) / 指定u_ID
                                const uSel = this.normalizeText(this.filter_log.u_ID);

                                return (this.all_log || []).filter(log => {
                                    // ✅ 1) 若有指定 rd_ID → 只留該筆
                                    if (rdId !== '' && this.normalizeText(log.rd_ID) !== rdId) return false;

                                    // ✅ 2) 負責人篩選
                                    if (uSel !== 'all') {
                                        const ownerR = this.getOwnerId(log, 'r'); // 原始負責人 u_ID
                                        const ownerU = this.getOwnerId(log, 'u'); // 更新後負責人 u_ID

                                        if (uSel === '') {
                                            // 未定：篩出「沒有填負責人」的資料
                                            // ✅ 原始/更新都沒有 u_ID 才算未定
                                            if (!(ownerR === '' && ownerU === '')) return false;
                                        } else {
                                            // 指定某位負責人：原始或更新其中一邊符合就算
                                            if (!(ownerR === uSel || ownerU === uSel)) return false;
                                        }
                                    }

                                    // ✅ 3) 內容關鍵字：搜尋 原始/更新 的「標題+內容+負責人」合併字串
                                    if (kwValue !== '') {
                                        const blob = this.getLogBlob(log).toLowerCase();
                                        if (!blob.includes(kwValue)) return false;
                                    }

                                    // ✅ 4) 異動緣由關鍵字：搜尋 cr_reason
                                    if (kwReason !== '') {
                                        const reason = this.normalizeText(log.cr_reason).toLowerCase();
                                        if (!reason.includes(kwReason)) return false;
                                    }

                                    return true;
                                });
                            },
                            teacherName() {
                                return this.all_teammumber.filter(e => Number(e.role_ID) === 4)?.[0]?.u_name || '-';
                            },
                            members() {
                                return this.all_teammumber.filter(e => Number(e.role_ID) === 6) || [];
                            },
                            exportNow() {
                                // ✅ 每次重新 render 時更新：如果你希望「按下匯出當下」才更新，改成在 exportPdf() 裡產生也行
                                const d = new Date();
                                const pad = (n) => String(n).padStart(2, '0');
                                return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
                            },
                            curEx() {
                                return this.exresultdata?.[this.cursor] || null;
                            },
                            nextEx() {
                                return this.exresultdata?.[this.cursor + 1] || null;
                            },
                            leftMonth() {
                                return this.months[this.cursor] || null
                            },
                            rightMonth() {
                                return this.months[this.cursor + 1] || null
                            },

                            leftFile() {
                                return this.getFile(this.leftMonth, this.leftFileIdx)
                            },
                            rightFile() {
                                return this.getFile(this.rightMonth, this.rightFileIdx)
                            },

                            leftMonthTitle() {
                                return this.leftMonth ? this.leftMonth.title : '-'
                            },
                            rightMonthTitle() {
                                return this.rightMonth ? this.rightMonth.title : '-'
                            },

                            leftHint() {
                                return this.leftMonth ? `截止日期：${this.leftMonth.ym}` : ''
                            },
                            rightHint() {
                                return this.rightMonth ? `截止日期：${this.rightMonth.ym}` : ''
                            },
                            subtitle() {
                                if (this.leftMonth && this.rightMonth) {
                                    return `目前對照：${this.leftMonth.title} ↔ ${this.rightMonth.title}`
                                }
                                return '左右對照相鄰月份檔案，點膠囊即可切換。'
                            },
                            // 預覽資料
                            exPreviewCards() {
                                const ex = Array.isArray(this.exresultdata) ? this.exresultdata : [];
                                const logs = Array.isArray(this.all_log) ? this.all_log : [];
                                const expected = Array.isArray(this.all_expected) ? this.all_expected : [];

                                return ex
                                    .slice() // 保險
                                    .sort((a, b) => String(a.sfd_submit_d).localeCompare(String(b.sfd_submit_d)))
                                    .map((cur, idx, arr) => {
                                        const end = this.toDateEnd(cur.sfd_submit_d); // 當天 23:59:59
                                        const start = (idx > 0) ?
                                            this.addDays(this.toDateStart(arr[idx - 1].sfd_submit_d), 1) // 上一筆截止日+1天
                                            :
                                            this.firstDayOfPrevMonth(cur.sfd_submit_d); // 第一筆：往前一個月的1號

                                        // ✅ 這期的成果（最穩：用 sfd_ID 直接綁）
                                        const exExpected = expected.filter(e => Number(e.sfd_ID) === Number(cur.sfd_ID));
                                        const rdIds = new Set(exExpected.map(e => Number(e.rd_ID)));

                                        // ✅ 這期的異動紀錄（時間區間 + rd_ID 屬於這期）
                                        const exLogs = logs.filter(l => {
                                            const t = this.toDateTime(l.cr_update_d);
                                            if (!t) return false;
                                            const inRange = (t >= start && t <= end);
                                            const inRd = rdIds.has(Number(l.rd_ID));
                                            return inRange && inRd;
                                        });

                                        return {
                                            ...cur,
                                            rangeStart: start,
                                            rangeEnd: end,
                                            expectedRows: exExpected,
                                            logs: exLogs
                                        };
                                    });
                            },
                            leftSnapshot() {
                                if (!this.curPair) return [];
                                return this.snapshotAt(this.toDayEnd(this.curPair.L.sfd_submit_d));
                            },
                            rightSnapshot() {
                                if (!this.curPair) return [];
                                return this.snapshotAt(this.toDayEnd(this.curPair.R.sfd_submit_d));
                            },
                            diffPairs() {
                                const ex = (this.exresultdata || []).slice().sort((a, b) => String(a.sfd_submit_d).localeCompare(String(b.sfd_submit_d)));

                                // (第 i 期, 第 i+1 期) 一組
                                const pairs = [];
                                for (let i = 0; i < ex.length - 1; i++) {
                                    pairs.push({
                                        L: ex[i],
                                        R: ex[i + 1]
                                    });
                                }
                                return pairs;
                            },
                            curPair() {
                                return this.diffPairs[this.cursor] || null;
                            },
                            canPrev() {
                                return this.cursor > 0;
                            },
                            canNext() {
                                return this.cursor < this.diffPairs.length - 1;
                            },

                            // ✅ 左右「截止日」時間點
                            leftCutoff() {
                                return this.curPair ? this.toDayEnd(this.curPair.L.sfd_submit_d) : null;
                            },
                            rightCutoff() {
                                return this.curPair ? this.toDayEnd(this.curPair.R.sfd_submit_d) : null;
                            },

                            // ✅ 左右「預期成果」快照（用 all_log 回推）
                            leftExpectedSnap() {
                                return this.leftCutoff ? this.snapshotAt(this.leftCutoff) : [];
                            },
                            rightExpectedSnap() {
                                return this.rightCutoff ? this.snapshotAt(this.rightCutoff) : [];
                            },

                            todayYmd() {
                                const d = new Date(); // 瀏覽器本地時間（台灣）
                                const pad = n => String(n).padStart(2, '0');
                                return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; // YYYY-MM-DD
                            },

                            filtered_AI() {
                                // ✅ 只顯示「到期(含今天)」的資料：sfd_submit_d <= today
                                // 因為都是 YYYY-MM-DD，所以直接字串比較即可
                                return (this.all_AI || [])
                                    .filter(x => (x.sfd_submit_d || '') <= this.todayYmd)
                                    .sort((a, b) => String(b.sfd_submit_d).localeCompare(String(a.sfd_submit_d))); // 新的在上面
                            }

                        },
                        methods: {
                            getRpRemark(reqId) {
                                const rp = this.getRpByReqId(reqId);
                                // 兼容不同欄位命名（有些你前面 modal 有 rp_teacher_remark）
                                return rp?.rp_remark || rp?.rp_teacher_remark || '';
                            },
                            getRpApproved(reqId) {
                                const rp = this.getRpByReqId(reqId);
                                return rp?.rp_approved_d || '';
                            },
                            getFileUrl(row, fileIndex = 0) {
                                // row = exresultdata 的其中一筆
                                // fileIndex：第幾個檔案（看你要顯示哪份 pdf）

                                const ym = (row.sfd_submit_d || '').slice(0, 7); // "2026-03"
                                // ✅ 你這裡要依你的檔名規則改
                                // 例：每個月固定一份：`${row.sfd_name}.pdf`
                                // 或固定叫：`系統需求分析表.pdf`
                                const filename = `${row.sfd_name}.pdf`;

                                return `files/${ym}/${filename}`;
                            },
                            encodeSafe(u) {
                                return encodeURI(u || '');
                            },
                            get_requirement() {
                                $.post("../modules/expected_outcome.php?do=get_now_group", item => {
                                        this.now_group.ID = JSON.parse(item)[0]["group_ID"]
                                        this.now_group.name = JSON.parse(item)[0]["group_name"]
                                        this.now_group.team_project_name = JSON.parse(item)[0]["team_project_name"]
                                    })
                                    .done(() => {
                                        $.post("../modules/expected_outcome.php?do=get_now_teammember", this.now_group, item => {
                                                this.all_teammumber = JSON.parse(item)["team_member"]
                                                this.now_team_ID = JSON.parse(item)["team_ID"]
                                            })
                                            .done(() => {
                                                $.post("../modules/expected_outcome.php?do=get_requirement", {
                                                    g: this.now_group,
                                                    now_team_ID: this.now_team_ID
                                                }, item => {
                                                    this.all_requirement = JSON.parse(item)["req"]
                                                    this.all_rp = JSON.parse(item)["rp"]
                                                    this.all_requirement.forEach(i => {
                                                        if (i.req_count) {
                                                            i.req_count = JSON.parse(i.req_count)
                                                        }
                                                    })
                                                    this.now_cohort_ID = JSON.parse(item)["cohort"]
                                                })
                                                this.get_expected()
                                                this.get_exresultdata()
                                                this.openAllLogs(false)
                                                this.addsfd_auto()
                                                this.get_AI_score()
                                            })
                                    })
                            },
                            addsfd_auto() {
                                // ✅ now_team_ID 還沒拿到就不要打
                                if (!this.now_team_ID) return;

                                // ✅ 可選：避免每次 get_requirement() 都重複打（你 get_requirement 會被很多地方呼叫）
                                if (this._addsfd_auto_done) return;

                                $.post("../modules/expected_teacher.php?do=add_sfd_auto", {
                                    team_ID: this.now_team_ID
                                }, (res) => {
                                    let json = null;
                                    try {
                                        json = (typeof res === "string") ? JSON.parse(res) : res;
                                    } catch (e) {}

                                    if (json?.status === "success") {
                                        // 你要不要提示看你：不提示也可以
                                        // toast({ type:'success', title:'自動產生', text: json.message || '' });
                                        this._addsfd_auto_done = true;

                                        // ✅ 產生完，順便刷新 diff 用的資料
                                        this.get_exresultdata();
                                    } else if (json?.status === "error") {
                                        // toast({ type:'error', title:'自動產生失敗', text: json.message || '' });
                                        console.warn("add_sfd_auto error:", json?.message, res);
                                    } else {
                                        console.warn("add_sfd_auto unknown response:", res);
                                    }
                                }).fail((xhr) => {
                                    console.error("add_sfd_auto fail:", xhr?.responseText || xhr);
                                });
                            },
                            get_exresultdata() {
                                $.post("../modules/expected_outcome.php?do=get_exresultdata", this.now_group, item => {
                                    this.exresultdata = JSON.parse(item)
                                })
                            },
                            startEdit(i) {
                                this.editReqId = i.req_ID;
                                const rp = this.getRpByReqId(i.req_ID);
                                this.editRowReq.req_title = i.req_title || '';
                                this.editRowReq.rp_comment = rp?.rp_comment || '';
                                this.editRowReq.rp_count = rp?.rp_count != null ?
                                    Number(rp.rp_count) :
                                    null;
                                // ✅ 關鍵：編輯時，rp_done 以「資料庫 rp_status」為主
                                if (Number(rp?.rp_status) === 3) {
                                    this.editRowReq.rp_done = 3;
                                } else {
                                    // 若資料庫不是完成，才回退用 rp_done
                                    this.editRowReq.rp_done = Number(rp?.rp_done || 0);
                                }
                            },

                            async submitEdit(i) {
                                // ===== 防呆：整理輸入 =====
                                const comment = (this.editRowReq.rp_comment ?? '').toString().trim();
                                const countRaw = this.editRowReq.rp_count;
                                const hasCountValue = countRaw !== null && countRaw !== undefined && countRaw !== '' && !Number.isNaN(Number(countRaw));

                                if (this.hasCount(i)) {
                                    if (!hasCountValue && comment === '') {
                                        alert('請完整填寫「數字回報」與「文字回報」');
                                        return;
                                    }
                                    if (!hasCountValue) {
                                        alert('請填寫「數字回報」');
                                        return;
                                    }
                                    if (comment === '') {
                                        alert('請填寫「文字回報」');
                                        return;
                                    }
                                } else {
                                    // 不需要量化：只要文字回報
                                    if (comment === '') {
                                        alert('請填寫「文字回報」');
                                        return;
                                    }
                                }

                                // ✅ 判斷這筆目前是否已經是完成狀態
                                const oldRp = this.getRpByReqId(i.req_ID);
                                const isAlreadyCompleted =
                                    Number(i?.status) === 3 || Number(oldRp?.rp_status) === 3;

                                // ✅ 若原本已完成，送出前先詢問
                                if (isAlreadyCompleted) {
                                    const result = await Swal.fire({
                                        icon: 'warning',
                                        title: '確定更新回報嗎？',
                                        text: '需要老師審核後才會標記為完成。',
                                        showCancelButton: true,
                                        confirmButtonText: '確定更新',
                                        cancelButtonText: '取消',
                                        reverseButtons: true
                                    });

                                    if (!result.isConfirmed) {
                                        return;
                                    }
                                }

                                // 要送去後端的資料
                                const payload = {
                                    req_ID: i.req_ID,
                                    rp_comment: this.editRowReq.rp_comment,
                                    rp_done: this.editRowReq.rp_done,
                                    team_ID: this.now_team_ID,
                                    group_ID: this.now_group.ID,
                                    rp_count: this.editRowReq.rp_count
                                };

                                // ✅ 送出到後端
                                $.post("../modules/expected_outcome.php?do=save_rp_comment", payload, (res) => {
                                    this.get_requirement();
                                    this.editReqId = null;
                                    this.editRowReq = {
                                        req_title: '',
                                        rp_comment: '',
                                        rp_done: 0
                                    };
                                }).fail(() => {
                                    alert('送出失敗，請稍後再試');
                                });
                            },
                            cancelEdit() {
                                this.editReqId = null;
                                this.editRowReq = {
                                    req_title: '',
                                    rp_comment: '',
                                    rp_done: 0
                                };
                            },
                            hasCount(i) {
                                // req_count 要是 [數字, 單位] 才算有量化
                                return Array.isArray(i.req_count) && i.req_count.length >= 2 && i.req_count[1] !== '';
                            },
                            getRpByReqId(reqId) {
                                return this.all_rp.find(x => Number(x.req_ID) === Number(reqId)) || null;
                            },

                            getRpDone(reqId) {
                                if (Number(this.editReqId) === Number(reqId)) {
                                    return Number(this.editRowReq.rp_done || 0);
                                }

                                const rp = this.getRpByReqId(reqId);

                                if (Number(rp?.rp_status) === 3) return 3;

                                return Number(rp?.rp_done || 0);
                            },


                            onDoneChange(reqId, evt) {
                                if (this.editReqId !== reqId) {
                                    evt.preventDefault();
                                    return;
                                }
                                this.editRowReq.rp_done = evt.target.checked ? 3 : 1;
                            },

                            // 這邊開始是專題預期成果
                            get_expected() {
                                $.post("../modules/expected_outcome.php?do=get_Expected", {
                                    tm: this.all_teammumber.filter(x => x.role_ID == 6)
                                }, item => {
                                    this.all_expected = JSON.parse(item);
                                })
                            },
                            openAddExpectedRow() {
                                // 開啟新增成果列
                                this.showAddExpectedRow = true;
                                this.editRow_Expected = {
                                    title: "",
                                    value: "",
                                    CEO: ""
                                };
                            },
                            cancelAddExpected() {
                                // 取消新增成果
                                this.showAddExpectedRow = false;
                                this.editRow_Expected = {
                                    title: "",
                                    value: "",
                                    CEO: ""
                                };
                            },
                            submitAddExpected() {
                                // 提交新增成果
                                const t = (this.editRow_Expected.title || "").trim();
                                const v = (this.editRow_Expected.value || "").trim();
                                if (t === "" || v === "") {
                                    alert("請先填寫「標題」與「內容」");
                                    return;
                                }
                                $.post("../modules/expected_outcome.php?do=add_expected", {
                                    ...this.editRow_Expected
                                }, (res) => {
                                    toast({
                                        type: 'success',
                                        title: '新增成功'
                                    })
                                    this.get_expected()
                                }).fail(() => {
                                    alert("新增失敗，請稍後再試")
                                });
                                this.showAddExpectedRow = false;
                                this.editRow_Expected = {
                                    title: "",
                                    value: "",
                                    CEO: ""
                                };
                            },
                            // ===== 預期成果：編輯流程（邏輯同 req） =====
                            startEditExpected(i) {
                                this.editExpectedId = i.rd_ID

                                // 依你的資料欄位：rd_title、rd_content、rd_u_ID_a、rd_done / rd_status（如果有）
                                this.editRowExpected.rd_ID = i.rd_ID;
                                this.editRowExpected.rd_title = i.rd_title || ''
                                this.editRowExpected.rd_content = i.rd_content || ''
                                // 負責人：如果後端有給 rd_u_ID_a 就用，沒有就先空
                                this.editRowExpected.rd_u_ID_a = i.rd_u_ID_a ?? ''
                                // 完成狀態：如果你有 rd_status 就優先，沒有就用 rd_done
                                if (Number(i?.rd_status) === 3) {
                                    this.editRowExpected.rd_done = 3
                                } else {
                                    this.editRowExpected.rd_done = Number(i?.rd_done || 0)
                                }
                            },

                            cancelEditExpected() {
                                this.editExpectedId = null;
                                this.editRowExpected = {
                                    rd_ID: null,
                                    rd_title: '',
                                    rd_content: '',
                                    rd_u_ID_a: '',
                                    rd_done: 0
                                };
                            },

                            submitEditExpected(i, type) {
                                if (type == 1) {
                                    const t = (this.editRowExpected.rd_title ?? '').toString().trim();
                                    const c = (this.editRowExpected.rd_content ?? '').toString().trim();
                                    if (t === '' || c === '') {
                                        alert('請完整填寫「標題」與「內容」');
                                        return;
                                    }

                                    // ✅ 暫存 payload（給 type==2 用）
                                    this.pendingExpectedPayload = {
                                        rd_ID: i.rd_ID,
                                        rd_title: this.editRowExpected.rd_title,
                                        rd_content: this.editRowExpected.rd_content,
                                        rd_u_ID_a: this.editRowExpected.rd_u_ID_a,
                                        rd_done: this.editRowExpected.rd_done,
                                        team_ID: this.now_team_ID,
                                        group_ID: this.now_group.ID,
                                    };

                                    // ✅ 開 modal（Bootstrap 5 正確）
                                    const el = document.getElementById('Expected_modal');
                                    bootstrap.Modal.getOrCreateInstance(el).show();
                                    return;
                                } else if (type == 2) {
                                    const reason = (this.editRowExpected.cr_reason ?? '').toString().trim();
                                    if (reason === '') {
                                        alert('請先填寫異動緣由');
                                        return;
                                    }

                                    if (!this.pendingExpectedPayload) {
                                        alert('找不到待送出的資料，請重新按一次「送出」');
                                        return;
                                    }

                                    const payload = {
                                        ...this.pendingExpectedPayload,
                                        cr_reason: reason,
                                    };

                                    $.post("../modules/expected_outcome.php?do=update_expected", payload, (res) => {
                                        toast({
                                            type: 'success',
                                            title: '更新成功'
                                        });
                                        this.get_expected();
                                        this.cancelEditExpected();

                                        // 關 modal
                                        const el = document.getElementById('Expected_modal');
                                        bootstrap.Modal.getInstance(el)?.hide();

                                        // 清暫存
                                        this.pendingExpectedPayload = null;
                                        this.editRowExpected.cr_reason = '';
                                    }).fail(() => {
                                        alert('更新失敗，請稍後再試');
                                    });
                                    return;
                                }
                            },
                            deleteExpected(i) {
                                if (i) {
                                    // ✅ 只有「開啟刪除視窗」時才清空
                                    this.editRowExpected.cr_reason = "";

                                    this.form = {
                                        rd_ID: i.rd_ID,
                                        cr_reason: "", // 這邊先空，等按確定刪除再填
                                    };

                                    this.editRowExpected.rd_title = i.rd_title;

                                    // 你原本用 jQuery modal（若是 BS5，建議改 bootstrap.Modal，我下面有給）
                                    $("#del_Expected_modal").modal("show");
                                    return;
                                }

                                // ✅ 這裡是按「確定刪除」才會走到
                                const reason = (this.editRowExpected.cr_reason ?? '').toString().trim();
                                if (reason === '') {
                                    alert('請先填寫異動緣由');
                                    return;
                                }

                                // ✅ 重要：把原因塞回 this.form，不然送出去還是空的
                                this.form.cr_reason = reason;

                                $("#del_Expected_modal").modal("hide");

                                $.post("../modules/expected_outcome.php?do=delete_expected", this.form, (res) => {
                                    toast({
                                        type: 'success',
                                        title: '刪除成功'
                                    });
                                    this.get_expected();
                                    this.editRowExpected.cr_reason = ""; // 刪完再清空
                                }).fail(() => {
                                    alert('刪除失敗，請稍後再試');
                                });
                            },
                            get_expectedDone(i) {
                                // 編輯中：以使用者目前勾選為主
                                if (Number(this.editExpectedId) === Number(i.rd_ID)) {
                                    return Number(this.editRowExpected.rd_done || 0);
                                }

                                // 非編輯：如果你資料有 rd_status 就優先
                                if (Number(i?.rd_status) === 3) return 3;

                                // 否則退回 rd_done
                                return Number(i?.rd_done || 0);
                            },
                            onExpectedDoneChange(i, evt) {
                                if (this.editExpectedId !== i.rd_ID) {
                                    evt.preventDefault();
                                    return;
                                }
                                this.editRowExpected.rd_done = evt.target.checked ? 3 : 1;
                            },
                            Expected_modal_close() {
                                $("#Expected_modal").modal("hide")
                                $("#del_Expected_modal").modal("hide")
                                this.editRowExpected.cr_reason = ""
                            },
                            exresultdata_modal_show() {
                                // ✅ 資料還沒載完就阻止開啟（避免預覽空白）
                                if (!this.now_group?.team_project_name) {
                                    alert('資料尚未載入完成，請稍後再試');
                                    return;
                                }
                                $("#exresultdata_modal").modal("show");
                            },
                            exportPdf() {
                                // ✅ 資料還沒載完就阻止匯出（避免匯出空白）
                                if (!this.now_group?.team_project_name) {
                                    alert('資料尚未載入完成，請稍後再試');
                                    return;
                                }

                                const el = document.getElementById('pdf_export');
                                if (!el) {
                                    alert('找不到匯出區塊 pdf_export');
                                    return;
                                }

                                // 檔名：專題名稱_預期成果_YYYYMMDD_HHmmss.pdf
                                const d = new Date();
                                const pad = (n) => String(n).padStart(2, '0');
                                const ts = `${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
                                const safeName = (this.now_group.team_project_name || '專題').replace(/[\\/:*?"<>|]/g, '_');
                                const filename = `${safeName}_預期成果_${ts}.pdf`;

                                const opt = {
                                    margin: [10, 10, 10, 10],
                                    filename,
                                    image: {
                                        type: 'jpeg',
                                        quality: 0.98
                                    },
                                    html2canvas: {
                                        scale: 2,
                                        useCORS: true,
                                        scrollY: 0
                                    },
                                    jsPDF: {
                                        unit: 'mm',
                                        format: 'a4',
                                        orientation: 'portrait'
                                    },
                                    pagebreak: {
                                        mode: ['css', 'legacy']
                                    }
                                };

                                html2pdf().set(opt).from(el).save();
                            },
                            toDateStart(ymd) {
                                // "YYYY-MM-DD" -> Date(本地 00:00:00)
                                if (!ymd) return null;
                                return new Date(`${String(ymd).slice(0, 10)}T00:00:00`);
                            },
                            toDateEnd(ymd) {
                                // "YYYY-MM-DD" -> Date(本地 23:59:59)
                                if (!ymd) return null;
                                return new Date(`${String(ymd).slice(0, 10)}T23:59:59`);
                            },
                            toDateTime(dt) {
                                // "YYYY-MM-DD HH:mm:ss" -> Date
                                if (!dt) return null;
                                return new Date(String(dt).replace(' ', 'T'));
                            },
                            addDays(date, days) {
                                if (!date) return null;
                                const d = new Date(date);
                                d.setDate(d.getDate() + Number(days || 0));
                                return d;
                            },
                            firstDayOfPrevMonth(submitYmd) {
                                // 第一筆：sfd_submit_d 往前一個月的 1 號
                                const d = this.toDateStart(submitYmd);
                                if (!d) return null;
                                const y = d.getFullYear();
                                const m = d.getMonth(); // 0~11（submit那個月）
                                // prev month: m-1
                                const firstPrev = new Date(y, m - 1, 1, 0, 0, 0);
                                return firstPrev;
                            },
                            fmtYmd(d) {
                                if (!d) return '-';
                                const pad = n => String(n).padStart(2, '0');
                                return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
                            },
                            toDateTime(dt) {
                                if (!dt) return null;
                                return new Date(String(dt).replace(' ', 'T'));
                            },
                            toDayEnd(ymd) {
                                // "YYYY-MM-DD" -> 當天 23:59:59
                                if (!ymd) return null;
                                return new Date(`${String(ymd).slice(0,10)}T23:59:59`);
                            },
                            parseLogArr(v) {
                                // 你的 cr_record / cr_update_data 是字串 JSON
                                if (Array.isArray(v)) return v;
                                if (v == null) return [];
                                try {
                                    const arr = JSON.parse(String(v));
                                    return Array.isArray(arr) ? arr : [];
                                } catch (e) {
                                    return [];
                                }
                            },
                            cloneObj(o) {
                                return JSON.parse(JSON.stringify(o));
                            },

                            // ✅ 用 log.cr_record 回推成一筆 expected row 的「舊狀態」
                            applyRecordToRow(row, log) {
                                const rec = this.parseLogArr(log.cr_record);
                                // rec: [title, content, ownerId]
                                row.rd_title = rec[0] ?? row.rd_title;
                                row.rd_content = rec[1] ?? row.rd_content;

                                // 負責人 id（你原本就是抓 arr[2]）
                                row.rd_u_ID_a = (rec[2] ?? row.rd_u_ID_a) || null;

                                // 負責人名字你 log 有 *_name 欄位
                                row.rd_u_name_a = (log.cr_record_name ?? row.rd_u_name_a) || null;

                                // 最後編輯者 / 時間：快照時通常不需要改，但你想也可回推
                                // row.rd_finish_d = log.cr_update_d;
                                return row;
                            },

                            // ✅ 產生「截止時間 T 的快照」：用 all_expected 最新狀態 + 把 T 之後的異動倒回去
                            snapshotAt(cutoffEnd) {
                                const T = cutoffEnd; // Date
                                const nowMap = new Map();

                                // 1) 先用「最新 all_expected」建一份可改的 map
                                (this.all_expected || []).forEach(e => {
                                    nowMap.set(Number(e.rd_ID), this.cloneObj(e));
                                });

                                // 2) 把所有 log 依時間「由新到舊」排序，方便倒回
                                const logsDesc = (this.all_log || [])
                                    .slice()
                                    .sort((a, b) => this.toDateTime(b.cr_update_d) - this.toDateTime(a.cr_update_d));

                                // 3) 只處理「發生在 T 之後」的 log：倒回去
                                for (const log of logsDesc) {
                                    const t = this.toDateTime(log.cr_update_d);
                                    if (!t || t <= T) continue;

                                    const rdId = Number(log.rd_ID);

                                    if (log.cr_type === 'UPDATE') {
                                        // T 之後的更新：倒回成更新前
                                        const row = nowMap.get(rdId);
                                        if (row) {
                                            this.applyRecordToRow(row, log);
                                            nowMap.set(rdId, row);
                                        }
                                    } else if (log.cr_type === 'INSERT') {
                                        // T 之後才新增：快照不該存在 -> 移除
                                        nowMap.delete(rdId);
                                    } else if (log.cr_type === 'DELETE') {
                                        // T 之後才刪除：快照應該存在 -> 復原（要靠 cr_record）
                                        const rec = this.parseLogArr(log.cr_record);
                                        if (rec.length) {
                                            const restored = {
                                                rd_ID: rdId,
                                                rd_title: rec[0] ?? '',
                                                rd_content: rec[1] ?? '',
                                                rd_u_ID_a: (rec[2] ?? '') || null,
                                                rd_u_name_a: log.cr_record_name ?? null,
                                            };
                                            nowMap.set(rdId, restored);
                                        }
                                    }
                                }

                                // 4) 回傳陣列（你可自己排序）
                                return Array.from(nowMap.values()).sort((a, b) => Number(a.rd_ID) - Number(b.rd_ID));
                            },
                            // ✅ 差異檢視：預期成果用快照（已回推）
                            getExpectedForSide(side) {
                                return side === 'L' ? this.leftExpectedSnap : this.rightExpectedSnap;
                            },

                            // ✅ 差異檢視：最低要求先用現有資料（目前沒 log 無法回推）
                            getReqForSide(side) {
                                return this.all_requirement || [];
                            },
                            getRpForSide(reqId) {
                                return (this.all_rp || []).find(x => Number(x.req_ID) === Number(reqId)) || null;
                            },



                            // 下面是假的
                            encodeSafe(u) {
                                return encodeURI(u || '')
                            },

                            stripPdf(name) {
                                return (name || '').replace(/\.pdf$/i, '')
                            },

                            getFile(month, idx) {
                                if (!month || !month.files || month.files.length === 0) return null
                                return month.files[idx] || month.files[0] || null
                            },

                            prevPage() {
                                if (!this.canPrev) return;
                                this.cursor--;
                            },

                            nextPage() {
                                if (!this.canNext) return;
                                this.cursor++;
                            },
                            epxected_openLog(i) {
                                this.filter_log.rd_ID = i?.rd_ID != null ? String(i.rd_ID) : '';
                                $.post("../modules/expected_outcome.php?do=get_log", {
                                    log: this.filter_log,
                                    tm: this.all_teammumber.filter(x => x.role_ID == 6)
                                }, item => {
                                    this.all_log = JSON.parse(item);
                                    $("#changerecordsdata_modal").modal("show");
                                });
                            },
                            epxected_closeLog() {
                                $("#changerecordsdata_modal").modal("hide");

                                // ✅ 若有備份就還原，避免使用者原本在 modal header 打的篩選被你「查看全部」洗掉
                                if (this.filter_log_backup) {
                                    this.filter_log = {
                                        ...this.filter_log_backup
                                    };
                                    this.filter_log_backup = null;
                                }
                            },
                            parseArr(v) {
                                if (Array.isArray(v)) return v; // 已經是陣列就直接回傳
                                if (v == null) return [];
                                const s = String(v).trim();
                                try {
                                    const arr = JSON.parse(s);
                                    return Array.isArray(arr) ? arr : [];
                                } catch (e) {
                                    return [];
                                }
                            },
                            normalizeText(v) {
                                return (v ?? '').toString().trim();
                            },
                            diffClass(a, b) {
                                // a/b：字串或任何值 → 正規化後比較
                                const A = this.normalizeText(a);
                                const B = this.normalizeText(b);
                                return (A !== B) ? 'diff-red' : '';
                            },
                            getTitle(log, type) {
                                // type: 'r' = record, 'u' = update
                                const arr = this.parseArr(type === 'u' ? log.cr_update_data : log.cr_record);
                                return this.normalizeText(arr[0]);
                            },
                            getContent(log, type) {
                                const arr = this.parseArr(type === 'u' ? log.cr_update_data : log.cr_record);
                                return this.normalizeText(arr[1]);
                            },
                            getOwner(log, type) {
                                // 負責人不在陣列裡，是 *_name 欄位
                                return this.normalizeText(type === 'u' ? log.cr_update_data_name : log.cr_record_name);
                            },
                            getOwnerId(log, type) {
                                // type: 'r' = record, 'u' = update
                                // ✅ 從陣列第 3 格抓 u_ID（沒有就回空字串）
                                const arr = this.parseArr(type === 'u' ? log.cr_update_data : log.cr_record);
                                return this.normalizeText(arr[2]);
                            },
                            getLogBlob(log) {
                                // 把原始/更新資料、負責人、類型都串成一串，方便 keyword 比對
                                const r = this.parseArr(log.cr_record);
                                const u = this.parseArr(log.cr_update_data);

                                const parts = [
                                    this.normalizeText(log.cr_type),
                                    // 原始
                                    this.normalizeText(r[0]), this.normalizeText(r[1]),
                                    this.normalizeText(log.cr_record_name),
                                    // 更新
                                    this.normalizeText(u[0]), this.normalizeText(u[1]),
                                    this.normalizeText(log.cr_update_data_name),
                                ];
                                return parts.filter(Boolean).join(' ');
                            },
                            openAllLogs(ok) {
                                // ✅ 先備份目前使用者在 modal header 輸入的篩選條件（避免你不想被清掉）
                                this.filter_log_backup = {
                                    ...this.filter_log
                                };

                                // ✅ 不做篩選：清空條件 → computed filtered_logs 會自動顯示全部
                                this.filter_log.rd_ID = "";
                                this.filter_log.value_key_word = "";
                                this.filter_log.chager_key_word = "";

                                // ✅ 撈全部異動紀錄（後端如果看到 log 都是空，就應該回全部）
                                $.post("../modules/expected_outcome.php?do=get_log", {
                                    log: this.filter_log,
                                    tm: this.all_teammumber.filter(x => x.role_ID == 6)
                                }, item => {
                                    this.all_log = JSON.parse(item);
                                    if (ok) {
                                        $("#changerecordsdata_modal").modal("show");
                                    }
                                });
                            },
                            exportDiffPdf(side) {
                                // side: 'L' 或 'R'
                                if (!this.curPair) {
                                    alert('目前沒有可下載的對照資料');
                                    return;
                                }
                                if (!this.now_group?.team_project_name) {
                                    alert('資料尚未載入完成，請稍後再試');
                                    return;
                                }

                                const id = (side === 'L') ? 'pdf_export_diff_L' : 'pdf_export_diff_R';

                                this.$nextTick(() => {
                                    const el = document.getElementById(id);
                                    if (!el) {
                                        alert('找不到匯出區塊：' + id);
                                        return;
                                    }

                                    const d = new Date();
                                    const pad = (n) => String(n).padStart(2, '0');
                                    const ts = `${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;

                                    const safeProject = (this.now_group.team_project_name || '專題').replace(/[\\/:*?"<>|]/g, '_');
                                    const fileTitle = (side === 'L' ? this.curPair.L.sfd_name : this.curPair.R.sfd_name) || (side === 'L' ? '左側' : '右側');
                                    const safeTitle = String(fileTitle).replace(/[\\/:*?"<>|]/g, '_');

                                    const filename = `${safeProject}_${safeTitle}_${ts}.pdf`;

                                    const opt = {
                                        margin: [10, 10, 10, 10],
                                        filename,
                                        image: {
                                            type: 'jpeg',
                                            quality: 0.98
                                        },
                                        html2canvas: {
                                            scale: 2,
                                            useCORS: true,
                                            scrollY: 0
                                        },
                                        jsPDF: {
                                            unit: 'mm',
                                            format: 'a4',
                                            orientation: 'portrait'
                                        },
                                        pagebreak: {
                                            mode: ['css', 'legacy']
                                        }
                                    };

                                    html2pdf().set(opt).from(el).save();
                                });
                            },
                            AI_show() {
                                this.get_AI_score(); // 先更新資料
                                const el = document.getElementById('AI_modal');
                                bootstrap.Modal.getOrCreateInstance(el).show(); // ✅ BS5 寫法
                            },
                            get_AI_score() {
                                $.post("../modules/expected_outcome.php?do=get_AI_score", {
                                    team_ID: this.now_team_ID
                                }, item => {
                                    this.all_AI = JSON.parse(item) || [];
                                })
                            },
                            go_show_all() {
                                window.location.href = "main.php#pages/expected_show_all.php";
                            }
                        },
                        mounted() {
                            this.get_requirement()

                            $("#ai_loading").show()

                            $.post("../modules/expected_outcome.php?do=auto_AI_score")
                                .done(res => {
                                    console.log("AI評分完成")
                                })
                                .always(() => {
                                    $("#ai_loading").hide()
                                })
                        }
                    }).mount("#outcome_app")
                }
            </script>

            <!-- ✅ 匯出 PDF 用（平常隱藏） -->
            <div style="display: none !important;">
                <div id="pdf_export" class="pdf-page">
                    <div class="pdf-head">
                        <div>專題名稱：{{ now_group.team_project_name || '-' }}</div>
                        <div>指導老師：{{ teacherName }}</div>
                        <div>
                            成員：
                            <span v-for="(m, idx) in members" :key="'m_'+idx">
                                {{ m.u_name }}<span v-if="idx !== members.length-1">、</span>
                            </span>
                        </div>
                    </div>

                    <div class="pdf-section-title">最低專題要求自我檢查</div>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">完成</th>
                                <th style="width:280px;">標題</th>
                                <th>回報</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in all_requirement" :key="'r_'+i.req_ID">
                                <td style="text-align:center;">{{ getRpDone(i.req_ID) === 3 ? '是' : '' }}</td>
                                <td>{{ i.req_title }}</td>
                                <td class="pdf-cell-break">
                                    <!-- 量化顯示：跟你畫面邏輯一致 -->
                                    <template v-if="hasCount(i)">
                                        預期：{{ i.req_count?.[0] }}{{ i.req_count?.[1] }}　
                                        回報：{{ (getRpByReqId(i.req_ID)?.rp_count ?? '') }}{{ i.req_count?.[1] }}
                                        <div v-if="getRpByReqId(i.req_ID)?.rp_comment">
                                            {{ getRpByReqId(i.req_ID)?.rp_comment }}
                                        </div>
                                    </template>
                                    <template v-else>
                                        {{ getRpByReqId(i.req_ID)?.rp_comment || '' }}
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pdf-section-title" style="margin-top:18px;">專題預期成果</div>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">完成</th>
                                <th style="width:260px;">標題</th>
                                <th>內容</th>
                                <th style="width:70px;">負責人</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in all_expected" :key="'e_'+e.rd_ID">
                                <td style="text-align:center;">{{ Number(get_expectedDone(e)) === 3 ? '是' : '' }}</td>
                                <td>{{ e.rd_title }}</td>
                                <td>{{ e.rd_content }}</td>
                                <td style="text-align:center;">{{ e.rd_u_name_a ?? '未定' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pdf-foot">
                        以上資料統計至{{ exportNow }}為止(匯出時間)。
                    </div>
                </div>
            </div>
            <!-- ✅ 差異檢視 匯出 PDF 用（不要 display:none，放到畫面外） -->
            <div style="position: fixed; left: -99999px; top: 0; width: 210mm; opacity: 0.01; pointer-events: none;">
                <!-- Left 匯出 -->
                <div id="pdf_export_diff_L" class="pdf-page">
                    <div class="pdf-head">
                        <div>專題名稱：{{ now_group.team_project_name || '-' }}</div>
                        <div>指導老師：{{ teacherName }}</div>
                        <div>
                            成員：
                            <span v-for="(m, idx) in members" :key="'DL_pm_'+idx">
                                {{ m.u_name }}<span v-if="idx !== members.length-1">、</span>
                            </span>
                        </div>
                    </div>

                    <div class="pdf-section-title">最低專題要求自我檢查</div>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">完成</th>
                                <th>標題</th>
                                <th style="width:200px;">回報</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in getReqForSide('L')" :key="'DL_req_'+i.req_ID">
                                <td style="text-align:center;">{{ getRpDone(i.req_ID) === 3 ? '是' : '' }}</td>
                                <td>{{ i.req_title }}</td>
                                <td class="pdf-cell-break">
                                    <template v-if="hasCount(i)">
                                        預期：{{ i.req_count?.[0] }}{{ i.req_count?.[1] }}　
                                        回報：{{ (getRpForSide(i.req_ID)?.rp_count ?? '') }}{{ i.req_count?.[1] }}
                                        <div v-if="getRpForSide(i.req_ID)?.rp_comment">
                                            {{ getRpForSide(i.req_ID)?.rp_comment }}
                                        </div>
                                    </template>
                                    <template v-else>
                                        {{ getRpForSide(i.req_ID)?.rp_comment || '' }}
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pdf-section-title" style="margin-top:18px;">專題預期成果</div>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">完成</th>
                                <th>標題</th>
                                <th>內容</th>
                                <th style="width:70px;">負責人</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in getExpectedForSide('L')" :key="'DL_e_'+e.rd_ID">
                                <td style="text-align:center;">{{ Number(e.rd_status) === 3 ? '是' : '' }}</td>
                                <td>{{ e.rd_title }}</td>
                                <td class="pdf-cell-break">{{ e.rd_content }}</td>
                                <td style="text-align:center;">{{ e.rd_u_name_a ?? '未定' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pdf-foot">
                        以上資料統計至：{{ curPair?.L?.sfd_submit_d }} 23:59:59 為止。
                    </div>
                </div>

                <!-- Right 匯出 -->
                <div id="pdf_export_diff_R" class="pdf-page">
                    <div class="pdf-head">
                        <div>專題名稱：{{ now_group.team_project_name || '-' }}</div>
                        <div>指導老師：{{ teacherName }}</div>
                        <div>
                            成員：
                            <span v-for="(m, idx) in members" :key="'DR_pm_'+idx">
                                {{ m.u_name }}<span v-if="idx !== members.length-1">、</span>
                            </span>
                        </div>
                    </div>

                    <div class="pdf-section-title">最低專題要求自我檢查</div>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">完成</th>
                                <th>標題</th>
                                <th style="width:200px;">回報</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in getReqForSide('R')" :key="'DR_req_'+i.req_ID">
                                <td style="text-align:center;">{{ getRpDone(i.req_ID) === 3 ? '是' : '' }}</td>
                                <td>{{ i.req_title }}</td>
                                <td class="pdf-cell-break">
                                    <template v-if="hasCount(i)">
                                        預期：{{ i.req_count?.[0] }}{{ i.req_count?.[1] }}　
                                        回報：{{ (getRpForSide(i.req_ID)?.rp_count ?? '') }}{{ i.req_count?.[1] }}
                                        <div v-if="getRpForSide(i.req_ID)?.rp_comment">
                                            {{ getRpForSide(i.req_ID)?.rp_comment }}
                                        </div>
                                    </template>
                                    <template v-else>
                                        {{ getRpForSide(i.req_ID)?.rp_comment || '' }}
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pdf-section-title" style="margin-top:18px;">專題預期成果</div>
                    <table class="pdf-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">完成</th>
                                <th>標題</th>
                                <th>內容</th>
                                <th style="width:70px;">負責人</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in getExpectedForSide('R')" :key="'DR_e_'+e.rd_ID">
                                <td style="text-align:center;">{{ Number(e.rd_status) === 3 ? '是' : '' }}</td>
                                <td>{{ e.rd_title }}</td>
                                <td class="pdf-cell-break">{{ e.rd_content }}</td>
                                <td style="text-align:center;">{{ e.rd_u_name_a ?? '未定' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pdf-foot">
                        以上資料統計至：{{ curPair?.R?.sfd_submit_d }} 23:59:59 為止。
                    </div>
                </div>
            </div>

        </div>