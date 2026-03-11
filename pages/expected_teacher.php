    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="css/expected_outcome.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/task.css?v=<?= time() ?>">
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
                <i class="fa-solid fa-chart-line me-2" style="color: #ffc107;"></i>專題預期成果
            </h1>
            <div>
                <!-- <button class="btn btn-primary" type="button" @click="sfd_modal_show">設定繳交期限</button>&emsp; -->
                <button class="btn btn-primary" type="button" @click="go_show_all">查看AI進度評分</button>&emsp;
                <button class="btn btn-primary" type="button" @click="exresultdata_modal_show">匯出當前預期成果</button>
            </div>
        </div>
        <div class="team-switch-container">
            <div class="team-switch-title">
                <i class="fa-solid fa-people-group"></i> 選擇專題小組
            </div>
            <div class="team-switch-buttons">
                <button
                    class="team-btn"
                    :class="{ active: now_team_ID === i.team_ID }"
                    v-for="i in all_team_ID"
                    @click="changeTab(i.team_ID)">
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
                        <h3>{{now_group.name}}：最低專題要求自我檢查</h3>
                        <p>專題必需完成之事項，此區域將記錄該小組的完成、回報狀況。</p>
                    </div>
                </div>
            </div>
            <div class="outcome-table-wrap">
                <table class="outcome-table fixed">
                    <colgroup>
                        <col style="width: 65px;">
                        <col style="width: 320px;">
                        <col>
                        <col style="width: 175px;">
                        <col style="width: 165px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>完成</th>
                            <th>標題</th>
                            <th>回報內容</th>
                            <th>最後回報時間/者</th>
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
                                        :checked="getRpDone(i.req_ID) === 3"
                                        @change="onDoneChange(i.req_ID, $event)" />
                                </div>
                            </td>

                            <td>
                                <strong>{{ i.req_title }}</strong>
                            </td>

                            <td v-if="all_rp.find(x => x.req_ID === i.req_ID)?.rp_comment || editReqId == i.req_ID">
                                <div v-if="hasCount(i)" class="qwrap">
                                    <div class="qpill">
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
                                    <!-- 量化 -->
                                    <div class="text-muted">
                                        <strong v-if="editReqId !== i.req_ID">
                                            {{ all_rp.find(x => x.req_ID === i.req_ID)?.rp_comment}}
                                        </strong>
                                    </div>
                                </div>

                                <!-- 質化 -->
                                <template v-else>
                                    <div class="text-muted">
                                        <strong
                                            v-if="editReqId !== i.req_ID">
                                            {{ all_rp.find(x => x.req_ID === i.req_ID)?.rp_comment}}
                                        </strong>
                                    </div>
                                </template>
                            </td>
                            <td v-else style="color: #adb5bd;">
                                尚未回報
                            </td>
                            <td>
                                {{ all_rp.find(x => x.req_ID === i.req_ID)?.rp_completed_d || '' }}
                                {{ all_rp.find(x => x.req_ID === i.req_ID)?.u_name || '' }}
                                <span
                                    :style="
                                        all_requirement.find(x => x.req_ID == i.req_ID)?.status === 0
                                        ? (all_rp.find(x => x.req_ID === i.req_ID)?.u_name
                                        ? 'color:#0d6efd;' :'')
                                        : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 1
                                        ? 'color:#ffc107;'
                                        : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 2
                                        ? 'color:#dc3545;'
                                        : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 3
                                        ? 'color:#198754;'
                                        : ''">
                                    {{ (all_requirement.find(x => x.req_ID == i.req_ID)?.status === 0 && all_rp.find(x => x.req_ID === i.req_ID)?.u_name) ? '(待老師查看)'  : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 1 ? '(待完整回報)' : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 2 ? '(請修改回報)' : all_requirement.find(x => x.req_ID == i.req_ID)?.status === 3 ? '(已通過)' : ''}}
                                </span>

                            </td>
                            <td>
                                <div class="actions" v-if="all_rp.find(x => x.req_ID === i.req_ID) && all_requirement.find(x => x.req_ID == i.req_ID)?.status === 0">
                                    <!-- 顯示模式：編輯 -->
                                    <button
                                        class="btn-soft btn-primary"
                                        type="button"
                                        @click="req_review('view',i)">
                                        <i class="fa-solid fa-magnifying-glass"></i>審查/查看
                                    </button>
                                </div>
                                <div class="actions" v-if="all_rp.find(x => x.req_ID === i.req_ID) && all_requirement.find(x => x.req_ID == i.req_ID)?.status !== 0">
                                    <button
                                        class="btn-soft btn-primary"
                                        type="button"
                                        @click="req_review('remark',i)">
                                        <i class="fa-solid fa-pen"></i>備註
                                    </button>
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
                </div>
            </div>
            <div class="outcome-table-wrap">
                <table class="outcome-table">
                    <colgroup>
                        <col style="width:65px;">
                        <col v-if="now_group.ID == 1" style="width:120px;">
                        <col style="width:220px;">
                        <col>
                        <col style="width:200px;">
                        <col style="width:120px;">
                        <col style="width:165px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>完成</th>
                            <th v-if="now_group.ID == 1">角色</th>
                            <th>標題</th>
                            <th>內容</th>
                            <th>最後編輯時間/者</th>
                            <th>負責人</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- ✅ 新增成果列：只有按下「新增成果」才出現 -->
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

                            <!-- 角色 -->
                            <td v-if="now_group.ID == 1" class="text-success fw-bold">
                                {{ getExpectedDisplayRole(i) || '-' }}
                            </td>

                            <!-- 標題 -->
                            <td>
                                <strong>{{ getExpectedDisplayTitle(i) }}</strong>
                            </td>

                            <!-- 內容 -->
                            <td class="text-muted">
                                <!-- 顯示模式 -->
                                <strong>{{ i.rd_content }}</strong>

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
                                <span>{{ i.rd_u_name_a ?? "未定" }}</span>

                            </td>
                            <!-- 操作 -->
                            <td>
                                <div class="actions">
                                    <button
                                        v-if="editExpectedId !== i.rd_ID"
                                        class="btn-soft btn-primary"
                                        type="button"
                                        @click="backEditExpected(i)">
                                        <i class="fa-solid fa-xmark"></i>退回
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
                                                    <td>{{ getExpectedDisplayFullTitle(e) }}</td>
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
                                                    <td>{{ getExpectedDisplayFullTitle(e) }}</td>
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
                                    <i class="fa-solid fa-square-xmark ms-auto"
                                        style="font-size: 24px; cursor:pointer;"
                                        data-bs-dismiss="modal"></i>
                                </h3>

                                <div class="text-muted" style="font-size:12px;">
                                    此為AI進度評分，僅供參考。AI會根據專題要求與預期成果自動生成，可能不完全準確，請以實際專題成果為主。
                                </div>

                                <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                                    <select v-model="AI_filter_type" class="form-select" style="width:180px;">
                                        <option value="team">依小組篩選</option>
                                        <option value="title">依紀錄標題篩選</option>
                                    </select>

                                    <select
                                        v-if="AI_filter_type === 'team'"
                                        v-model="AI_filter_value"
                                        class="form-select"
                                        style="width:180px;">
                                        <option value="">請選擇小組</option>
                                        <option v-for="g in AI_team_options" :key="g.sfd_team_ID" :value="String(g.sfd_team_ID)">
                                            {{ g.team_project_name }}
                                        </option>
                                    </select>

                                    <select
                                        v-if="AI_filter_type === 'title'"
                                        v-model="AI_filter_value"
                                        class="form-select"
                                        style="width:180px;">
                                        <option value="">請選擇紀錄標題</option>
                                        <option v-for="name in AI_title_options" :key="name" :value="name">
                                            {{ name }}
                                        </option>
                                    </select>
                                </div>

                                <div v-if="filtered_AI.length" style="max-height:60vh; overflow:auto; margin-top:10px;">
                                    <div class="outcome-table-wrap modal-table-wrap">
                                        <table class="outcome-table fixed">
                                            <colgroup>
                                                <col style="width:120px;">
                                                <col style="width:130px;">
                                                <col style="width:70px;">
                                                <col style="width:80px;">
                                                <col>
                                            </colgroup>
                                            <thead>
                                                <tr>
                                                    <th>{{ AI_filter_type === 'team' ? '紀錄標題' : '專題名稱' }}</th>
                                                    <th>截止日</th>
                                                    <th>分數</th>
                                                    <th>排名</th>
                                                    <th style="text-align:left;">建議</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="r in filtered_AI" :key="r.sfd_ID">
                                                    <td>
                                                        <template v-if="AI_filter_type === 'team'">
                                                            {{ r.sfd_name }}
                                                        </template>
                                                        <template v-else>
                                                            {{ r.team_project_name }}
                                                        </template>
                                                    </td>
                                                    <td>{{ r.sfd_submit_d }}</td>
                                                    <td>{{ r.sfd_score ?? '—' }}</td>
                                                    <td>{{ r.sfd_rank ?? '—' }}/{{ r.total_teams ?? '—' }}</td>
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
                <div class="modal fade" id="req_review_modal" data-bs-backdrop="static" data-bs-keyboard="false">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h3 style="display:flex;align-items:center;justify-content: space-between;width:100%;">
                                    <span>審查/查看</span>
                                    <i class="fa-solid fa-square-xmark ms-auto"
                                        style="font-size: 24px; cursor:pointer;"
                                        data-bs-dismiss="modal"></i>
                                </h3>
                            </div>
                            <div class="modal-body">
                                <div class="mb-2">
                                    <div><strong>需求標題：</strong>{{ selectedReq?.req_title }}</div>
                                    <div><strong>回報內容：</strong><textarea class="form-control" disabled>{{ selectedRp?.rp_comment || '（無）' }}</textarea></div>
                                    <div><strong>最後回報：</strong>{{ selectedRp?.rp_completed_d || '' }} {{ selectedRp?.u_name || '' }}</div>
                                    <div><strong>最後審查：</strong>{{ selectedRp?.rp_approved_d || '' }}</div>
                                </div>
                                <span><strong>老師備註：</strong></span>
                                <textarea class="form-control" v-model="req_review_remark" style="width:100%"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button
                                    v-if="![1,3].includes(Number(selectedReq?.status))"
                                    class="btn btn-primary"
                                    style="margin-right: 5px;"
                                    @click="req_review('pass')">
                                    通過並更新備註
                                </button>
                                <button class="btn btn-warning" style="margin-right: 5px;" @click="req_review('update_only')">僅更新備註</button>
                                <button class="btn btn-danger" style="margin-right: 5px;" @click="req_review('reject')">退回並更新備註</button>
                                <button class="btn" data-bs-dismiss="modal">取消</button>
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
                                            {{ i.rd_ID }}({{ getExpectedDisplayFullTitle(i) }})
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
                                    <i class="fa-solid fa-square-xmark ms-auto"
                                        style="font-size: 24px; cursor:pointer;"
                                        @click="epxected_closeLog"></i>
                                </div>
                            </div>
                            <div class="modal-body" style="max-height: 70vh;overflow-y: auto;   /* 超出就出現垂直捲軸 */">
                                <table class="table table-striped" style="text-align:center;">
                                    <thead>
                                        <colgroup>
                                            <col style="width:110px;">
                                            <col style="width:90px;">
                                            <col>
                                            <col>
                                            <col>
                                        </colgroup>
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
                                            <td>{{ log.cr_type }}</td>
                                            <td class="text-start log-cell">
                                                <ul class="m-0 ps-3" v-if="parseArr(log.cr_record).length">
                                                    <li>標題：{{ getExpectedDisplayFullTitleByText(parseArr(log.cr_record).slice(0, 1)[0]) }}</li>
                                                    <li>內容：{{parseArr(log.cr_record).slice(1, 2)[0]}}</li>
                                                    <li>負責：{{ log.cr_record_name }}</li>
                                                </ul>
                                                <span v-else>{{ log.cr_record }}</span>
                                            </td>
                                            <td class="text-start log-cell">
                                                <ul class="m-0 ps-3" v-if="parseArr(log.cr_update_data).length">
                                                    <li :class="diffClass(getExpectedDisplayFullTitleByText(getTitle(log, 'u')), getExpectedDisplayFullTitleByText(getTitle(log, 'r')))">
                                                        標題：{{ getExpectedDisplayFullTitleByText(getTitle(log, 'u')) }}
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
                                                    <td>{{ getExpectedDisplayFullTitle(e) }}</td>
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
            <!-- AI評分 loading -->
            <div id="ai_loading" class="ai-loading">
                <div class="ai-loading-box">
                    <div class="spinner"></div>
                    <div class="ai-loading-text">進度評分中...請稍後</div>
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
                                editRowReq: {
                                    req_title: '',
                                    rp_comment: '',
                                    rp_done: 0
                                },
                                editRow_Expected: {
                                    role: "",
                                    title: "",
                                    value: "",
                                    CEO: "",
                                },
                                all_expected: [],
                                editExpectedId: null, // 預期成果目前正在編輯哪一列（rd_ID）
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
                                sfd_tab: "all",
                                sfd: {
                                    title: "",
                                    date: "",
                                    month: "",
                                    day: "",
                                },
                                all_sfd: [],
                                editSfdId: null,
                                editRowSfd: {
                                    sfd_ID: null,
                                    sfd_name: '',
                                    sfd_submit_d: ''
                                },

                                cursor: 0,
                                leftFileIdx: 0,
                                rightFileIdx: 0,
                                selectedReq: null, // ✅ 存使用者點擊的那筆 requirement (i)
                                selectedRp: null, // ✅ 也把該 req_ID 對應的回報資料抓出來（可顯示回報內容/備註）
                                req_review_remark: '', // ✅ modal textarea 綁定用

                                AI_filter_type: 'team',
                                AI_filter_value: '',
                                all_AI: [],
                            }
                        },
                        watch: {
                            AI_filter_type() {
                                if (this.AI_filter_type === 'team') {
                                    this.AI_filter_value = this.AI_team_options.length ?
                                        String(this.AI_team_options[0].sfd_team_ID) :
                                        '';
                                } else {
                                    this.AI_filter_value = this.AI_title_options.length ?
                                        this.AI_title_options[0] :
                                        '';
                                }
                            },
                        },
                        computed: {
                            sfdTabName() {
                                if (this.sfd_tab === 'all') return '全部';
                                const t = (this.all_team_ID || []).find(x => String(x.team_ID) === String(this.sfd_tab));
                                return t ? t.team_project_name : '（未選擇）';
                            },
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
                                return this.all_teammumber
                                    .find(e =>
                                        String(e.team_ID) === String(this.now_team_ID) &&
                                        Number(e.role_ID) === 4
                                    )?.u_name || '-';
                            },
                            members() {
                                return this.all_teammumber.filter(e =>
                                    String(e.team_ID) === String(this.now_team_ID) &&
                                    Number(e.role_ID) === 6
                                );
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
                            AI_due_data() {
                                return (this.all_AI || [])
                                    .filter(x => x.sfd_submit_d && this.isAIDue(x.sfd_submit_d));
                            },

                            AI_team_options() {
                                const map = new Map();

                                this.AI_due_data.forEach(x => {
                                    const key = String(x.sfd_team_ID);
                                    if (!map.has(key)) {
                                        map.set(key, {
                                            sfd_team_ID: x.sfd_team_ID,
                                            team_project_name: x.team_project_name || `第${x.sfd_team_ID}組`
                                        });
                                    }
                                });

                                return [...map.values()].sort((a, b) => Number(a.sfd_team_ID) - Number(b.sfd_team_ID));
                            },

                            AI_title_options() {
                                return [...new Set(
                                    this.AI_due_data
                                    .map(x => x.sfd_name)
                                    .filter(x => x && x.trim())
                                )];
                            },

                            filtered_AI() {
                                let data = [...this.AI_due_data];

                                if (!this.AI_filter_value) return data;

                                if (this.AI_filter_type === 'team') {
                                    data = data.filter(x => String(x.sfd_team_ID) === String(this.AI_filter_value));
                                    data.sort((a, b) => new Date(a.sfd_submit_d) - new Date(b.sfd_submit_d));
                                } else {
                                    data = data.filter(x => x.sfd_name === this.AI_filter_value);
                                    data.sort((a, b) => Number(a.sfd_team_ID) - Number(b.sfd_team_ID));
                                }

                                return data;
                            },
                            isRoleMode() {
                                return String(this.now_group.ID) === '1';
                            },

                            roleOptions() {
                                const set = new Set();

                                (this.all_expected || []).forEach(row => {
                                    const rawTitle = String(row.rd_title || '').trim();
                                    if (!rawTitle) return;

                                    if (rawTitle.includes('|>')) {
                                        const role = rawTitle.split('|>')[0].trim();
                                        if (role) set.add(role);
                                    }

                                    // 如果你之後 get_expected 已經先拆好 rd_role，也一起收
                                    if (row.rd_role && String(row.rd_role).trim()) {
                                        set.add(String(row.rd_role).trim());
                                    }
                                });

                                return [...set];
                            },

                        },
                        methods: {
                            changeTab(team_ID) {
                                if (!team_ID) return;
                                this.refreshTeamContext(team_ID);
                            },
                            get_all_tm() {
                                $.post("../modules/T_req&task.php?do=get_now_teammember", item => {
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
                                        this.get_expected()
                                    })
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
                                $.post("../modules/expected_outcome.php?do=get_now_group")
                                    .done(item => {
                                        const rows = JSON.parse(item) || []; // ✅ 這裡是陣列

                                        // ✅ 如果 now_team_ID 還沒設定，就先用第一筆當預設
                                        if (!this.now_team_ID && rows.length > 0) {
                                            this.now_team_ID = rows[0].team_ID;
                                        }

                                        // ✅ 找到目前選到的 team 那一筆
                                        const row = rows.find(x => String(x.team_ID) === String(this.now_team_ID)) || null;

                                        this.now_group.ID = row?.group_ID || "";
                                        this.now_group.name = row?.group_name || "";
                                        this.now_group.team_project_name = row?.team_project_name || "";
                                    })
                                    .done(() => {
                                        $.post("../modules/expected_outcome.php?do=get_now_teammember", this.now_group, item => {
                                                const data = JSON.parse(item) || {};
                                                this.all_teammumber = data.team_member || [];
                                                this.now_team_ID = data.team_ID || this.now_team_ID;
                                            })
                                            .done(() => {
                                                this.get_requirement2();
                                                this.get_all_tm();
                                                this.get_exresultdata();
                                                this.openAllLogs(false);
                                            });
                                    });
                            },
                            get_requirement2() {
                                return $.post("../modules/expected_outcome.php?do=get_requirement", {
                                    g: this.now_group,
                                    now_team_ID: this.now_team_ID
                                }, item => {
                                    this.all_requirement = JSON.parse(item)["req"] || [];
                                    this.all_rp = JSON.parse(item)["rp"] || [];
                                    this.all_requirement.forEach(i => {
                                        if (i.req_count) i.req_count = JSON.parse(i.req_count);
                                    });
                                    this.now_cohort_ID = JSON.parse(item)["cohort"];
                                });
                            },

                            get_exresultdata() {
                                return $.post("../modules/expected_outcome.php?do=get_exresultdata", {
                                    team_ID: this.now_team_ID
                                }, item => {
                                    if (!JSON.parse(item)) {
                                        $.post("../modules/expected_teacher.php?do=add_sfd_auto", {
                                            team_ID: this.now_team_ID
                                        });
                                    } else {
                                        this.exresultdata = JSON.parse(item) || [];
                                    }
                                });
                            },
                            get_exresultdata() {
                                $.post("../modules/expected_outcome.php?do=get_exresultdata", {
                                    team_ID: this.now_team_ID
                                }, item => {
                                    if (!JSON.parse(item)) {
                                        // T第一次進入時，若該團隊尚未設定預期成果時間，會自動設定未來12個月的預期成果資料
                                        $.post("../modules/expected_teacher.php?do=add_sfd_auto", {
                                            team_ID: this.now_team_ID
                                        }, item => {})
                                    } else {
                                        this.exresultdata = JSON.parse(item)
                                    }
                                })
                            },
                            cancelEdit() {
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

                                const rp = this.getRpByReqId(reqId);

                                if (Number(rp?.rp_status) === 3) return 3;

                                return Number(rp?.rp_done || 0);
                            },


                            onDoneChange(reqId, evt) {
                                this.editRowReq.rp_done = evt.target.checked ? 3 : 1;
                            },

                            // 這邊開始是專題預期成果
                            get_expected() {
                                $.post("../modules/expected_outcome.php?do=get_Expected", {
                                    tm: this.all_teammumber.filter(x => x.team_ID == this.now_team_ID && x.role_ID == 6)
                                }, item => {
                                    const rows = JSON.parse(item) || [];

                                    this.all_expected = rows.map(row => {
                                        const parsed = this.parseExpectedTitle(row.rd_title);
                                        return {
                                            ...row,
                                            rd_role: parsed.role,
                                            rd_title_pure: parsed.title
                                        };
                                    });
                                });
                            },
                            submitAddExpected() {
                                const role = (this.editRow_Expected.role || "").trim();
                                const t = (this.editRow_Expected.title || "").trim();
                                const v = (this.editRow_Expected.value || "").trim();

                                if (t === "" || v === "") {
                                    alert("請先填寫「標題」與「內容」");
                                    return;
                                }

                                const finalTitle = this.buildExpectedTitle(role, t);

                                $.post("../modules/expected_outcome.php?do=add_expected", {
                                    ...this.editRow_Expected,
                                    title: finalTitle
                                }, (res) => {
                                    toast({
                                        type: 'success',
                                        title: '新增成功'
                                    });
                                    this.get_expected();
                                }).fail(() => {
                                    alert("新增失敗，請稍後再試");
                                });

                                this.editRow_Expected = {
                                    role: "",
                                    title: "",
                                    value: "",
                                    CEO: ""
                                };
                            },
                            get_expectedDone(i) {
                                // 非編輯：如果你資料有 rd_status 就優先
                                if (Number(i?.rd_status) === 3) return 3;

                                // 否則退回 rd_done
                                return Number(i?.rd_done || 0);
                            },

                            exresultdata_modal_show() {
                                if (!this.now_team_ID) return;

                                this.refreshTeamContext(this.now_team_ID).then(() => {
                                    if (!this.now_group?.team_project_name) {
                                        alert('資料尚未載入完成，請稍後再試');
                                        return;
                                    }
                                    $("#exresultdata_modal").modal("show");
                                });
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
                            get_sfd() {
                                $.post("../modules/expected_teacher.php?do=get_sfd", {
                                    tm: this.all_team_ID
                                }, item => {
                                    this.all_sfd = JSON.parse(item);
                                })
                            },
                            addsfd_submit() {
                                if (!this.sfd.title || !this.sfd.date) {
                                    alert("請完整填寫「標題」與「截止日」");
                                    return;
                                }
                                $.post("../modules/expected_teacher.php?do=add_sfd", {
                                    team_ID: this.sfd_tab,
                                    sfd_title: this.sfd.title,
                                    sfd_date: this.sfd.date,
                                    all_team_ID: this.all_team_ID
                                }, res => {
                                    if (JSON.parse(res).status == "success") {
                                        toast({
                                            type: 'success',
                                            title: '新增成功'
                                        })
                                        this.sfd = {
                                            title: "",
                                            date: "",
                                            month: this.sfd.month,
                                            day: this.sfd.day
                                        }
                                    } else {
                                        alert(JSON.parse(res).message)
                                        return;
                                    }
                                    this.get_sfd()
                                }).fail(() => {
                                    alert("新增失敗，請稍後再試")
                                });
                            },
                            del_sfd(i) {
                                if (i == "submit") {
                                    this.sfd = {
                                        title: "",
                                        date: ""
                                    }
                                    toast({
                                        type: 'success',
                                        title: '清空成功'
                                    })
                                } else {
                                    $.post("../modules/expected_teacher.php?do=del_sfd", {
                                        sfd_ID: i.sfd_ID,
                                        date: this.all_sfd.filter(s => s.sfd_ID == i.sfd_ID)[0]?.sfd_submit_d
                                    }, res => {
                                        if (JSON.parse(res).status != "success") {
                                            alert(JSON.parse(res).message)
                                            return;
                                        } else {
                                            toast({
                                                type: 'success',
                                                title: '刪除成功'
                                            })
                                            this.get_sfd()
                                        }
                                    })
                                }
                                $("#sfd_edit_modal").modal("show");
                            },
                            addsfd_auto() {

                                if (!this.sfd.month || !this.sfd.day) {
                                    alert("請完整填寫「月份」與「日期」")
                                    return
                                }

                                if (this.sfd.month < 1 || this.sfd.month > 12) {
                                    alert("月份請輸入 1~12")
                                    return
                                }

                                if (this.sfd.day < 1 || this.sfd.day > 28) {
                                    alert("日期請輸入 1~28")
                                    return
                                }

                                const today = new Date()
                                const startYear = today.getFullYear()
                                const startMonth = today.getMonth() + 1 // JS 月份從0開始

                                let autoData = []

                                for (let i = 1; i <= this.sfd.month; i++) {

                                    let year = startYear
                                    let month = startMonth + i

                                    // 處理跨年
                                    if (month > 12) {
                                        year += Math.floor((month - 1) / 12)
                                        month = ((month - 1) % 12) + 1
                                    }

                                    const mm = String(month).padStart(2, "0")
                                    const dd = String(this.sfd.day).padStart(2, "0")

                                    autoData.push({
                                        sfd_title: `截止至${month}/${dd}的預期成果`,
                                        sfd_date: `${year}-${mm}-${dd}`
                                    })
                                }

                                $.post("../modules/expected_teacher.php?do=add_sfd_auto", {
                                    team_ID: this.sfd_tab,
                                    autoData: autoData,
                                })
                            },
                            edit_sfd(i) {
                                this.editSfdId = i.sfd_ID
                                this.editRowSfd = {
                                    sfd_ID: i.sfd_ID,
                                    sfd_name: i.sfd_name || '',
                                    sfd_submit_d: (i.sfd_submit_d || '').slice(0, 10)
                                }
                            },

                            cancel_sfd_edit() {
                                this.editSfdId = null
                                this.editRowSfd = {
                                    sfd_ID: null,
                                    sfd_name: '',
                                    sfd_submit_d: ''
                                }
                            },

                            submit_sfd(i) {
                                if (!this.editRowSfd.sfd_name || !this.editRowSfd.sfd_submit_d) {
                                    alert("請完整填寫標題與截止日")
                                    return
                                }

                                $.post("../modules/expected_teacher.php?do=update_sfd", {
                                    sfd_ID: this.editRowSfd.sfd_ID,
                                    sfd_name: this.editRowSfd.sfd_name,
                                    sfd_submit_d: this.editRowSfd.sfd_submit_d
                                }, res => {

                                    if (res.status !== "success") {
                                        alert(res.message)
                                        return
                                    }

                                    toast({
                                        type: 'success',
                                        title: res.message || '更新成功'
                                    })
                                    this.cancel_sfd_edit()
                                    this.get_sfd()

                                }, "json").fail(() => {
                                    alert("更新失敗，請稍後再試")
                                })
                            },
                            isSfdExpired(ymd) {
                                if (!ymd) return false;
                                const d = new Date(String(ymd).slice(0, 10) + "T00:00:00");
                                const today = new Date();
                                today.setHours(0, 0, 0, 0); // 今天 00:00:00
                                return d < today;
                            },
                            req_review(w, i) {
                                if (i) {
                                    this.selectedReq = i;
                                    this.selectedRp = this.all_rp.find(x => Number(x.req_ID) === Number(i.req_ID)) || null;
                                    this.req_review_remark = this.selectedRp?.rp_remark || this.selectedRp?.rp_teacher_remark || '';
                                }

                                switch (w) {
                                    case "view":
                                        $("#req_review_modal").modal("show");
                                        break;
                                    case "remark":
                                        $("#req_review_modal").modal("show");
                                        break;
                                    case "pass":
                                        $.post("../modules/expected_teacher.php?do=req_review", {
                                            status: 3,
                                            req_ID: this.selectedReq.req_ID,
                                            remark: this.req_review_remark,
                                            team_ID: this.now_team_ID
                                        })
                                        toast({
                                            type: 'success',
                                            title: '更新成功'
                                        })
                                        $("#req_review_modal").modal("hide");
                                        this.get_requirement()
                                        break;
                                    case "update_only":
                                        $.post("../modules/expected_teacher.php?do=req_review", {
                                            status: 10,
                                            req_ID: this.selectedReq.req_ID,
                                            remark: this.req_review_remark,
                                            team_ID: this.now_team_ID
                                        })
                                        toast({
                                            type: 'success',
                                            title: '更新成功'
                                        })
                                        $("#req_review_modal").modal("hide");
                                        this.get_requirement()
                                        break;
                                    case "reject":
                                        $.post("../modules/expected_teacher.php?do=req_review", {
                                            status: 2,
                                            req_ID: this.selectedReq.req_ID,
                                            remark: this.req_review_remark,
                                            team_ID: this.now_team_ID
                                        })
                                        toast({
                                            type: 'success',
                                            title: '更新成功'
                                        })
                                        $("#req_review_modal").modal("hide");
                                        this.get_requirement()
                                        break;
                                }
                            },
                            refreshTeamContext(team_ID) {
                                // 先把畫面資料清空，避免還看到上一組的殘影
                                this.all_requirement = [];
                                this.all_rp = [];
                                this.all_expected = [];
                                this.exresultdata = [];

                                this.now_team_ID = team_ID;

                                // 1) 先抓 now_group（依 team_ID 選到那筆）
                                return $.post("../modules/expected_outcome.php?do=get_now_group")
                                    .then(item => {
                                        const rows = JSON.parse(item) || [];
                                        const row = rows.find(x => String(x.team_ID) === String(this.now_team_ID)) || null;

                                        this.now_group.ID = row?.group_ID || "";
                                        this.now_group.name = row?.group_name || "";
                                        this.now_group.team_project_name = row?.team_project_name || "";
                                    })
                                    .then(() => {
                                        // 2) 更新目前團隊的成員（你已經有 all_teammumber=全部人的話就直接 filter）
                                        this.now_teammumber = (this.all_teammumber || []).filter(m => String(m.team_ID) === String(this.now_team_ID));

                                        // 3) 重新抓當前團隊資料（等三個都完成）
                                        return $.when(
                                            this.get_requirement2(),
                                            this.get_expected(),
                                            this.get_exresultdata()
                                        );
                                    });
                            },
                            AI_show() {
                                this.get_AI_score(() => {
                                    const el = document.getElementById('AI_modal');
                                    bootstrap.Modal.getOrCreateInstance(el).show();
                                });
                            },

                            get_AI_score(callback = null) {
                                $.post("../modules/expected_teacher.php?do=get_AI_score", item => {
                                    this.all_AI = JSON.parse(item) || [];

                                    this.AI_filter_type = 'team';
                                    this.AI_filter_value = this.AI_team_options.length ?
                                        String(this.AI_team_options[0].sfd_team_ID) :
                                        '';
                                    if (callback) callback();
                                });
                            },
                            isAIDue(ymd) {
                                if (!ymd) return false;

                                const d = new Date(String(ymd).slice(0, 10) + "T00:00:00");
                                const today = new Date();
                                today.setHours(0, 0, 0, 0);

                                // 截止日 <= 今天，才算可顯示
                                return d <= today;
                            },
                            go_show_all() {
                                window.location.href = "main.php#pages/expected_show_all.php";
                            },
                            parseExpectedTitle(rawTitle) {
                                const s = String(rawTitle || '').trim();

                                if (!s.includes('|>')) {
                                    return {
                                        role: '',
                                        title: s
                                    };
                                }

                                const parts = s.split('|>');
                                return {
                                    role: String(parts[0] || '').trim(),
                                    title: String(parts.slice(1).join('|>') || '').trim()
                                };
                            },

                            buildExpectedTitle(role, title) {
                                const r = String(role || '').trim();
                                const t = String(title || '').trim();

                                if (String(this.now_group.ID) !== '1') return t;
                                if (!r) return t;
                                return `${r}|>${t}`;
                            },

                            getExpectedDisplayRole(row) {
                                const parsed = this.parseExpectedTitle(row?.rd_title || '');
                                return String(this.now_group.ID) === '1' ? parsed.role : '';
                            },

                            getExpectedDisplayTitle(row) {
                                const parsed = this.parseExpectedTitle(row?.rd_title || '');
                                return String(this.now_group.ID) === '1' ? parsed.title : String(row?.rd_title || '');
                            },

                            getExpectedDisplayFullTitle(row) {
                                const parsed = this.parseExpectedTitle(row?.rd_title || '');
                                if (String(this.now_group.ID) === '1' && parsed.role) {
                                    return `(${parsed.role})${parsed.title}`;
                                }
                                return String(row?.rd_title || '');
                            },

                            getExpectedDisplayFullTitleByText(rawTitle) {
                                const parsed = this.parseExpectedTitle(rawTitle || '');
                                if (String(this.now_group.ID) === '1' && parsed.role) {
                                    return `(${parsed.role})${parsed.title}`;
                                }
                                return parsed.title || String(rawTitle || '');
                            },
                            backEditExpected(i) {
                                $.post("../modules/expected_outcome.php?do=backEditExpected", i)
                                    .done(() => {
                                        this.get_requirement()
                                        toast({
                                            type: 'success',
                                            title: '更新成功'
                                        });
                                    })

                            }
                        },
                        mounted() {
                            this.get_requirement()

                            $("#ai_loading").show()

                            $.post("../modules/expected_outcome.php?do=auto_AI_score")
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
                                <td>{{ getExpectedDisplayFullTitle(e) }}</td>
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
                                <td>{{ getExpectedDisplayFullTitle(e) }}</td>
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
                                <td>{{ getExpectedDisplayFullTitle(e) }}</td>
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