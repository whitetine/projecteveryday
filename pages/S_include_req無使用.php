<div class="card pretty-card">
    <div class="card-body">
        <h5 class="card-title mb-2">
            <i class="fa-solid fa-clipboard-list me-2"></i>當前最低專題需求（{{ now_group.name }}）
        </h5>
        <!-- **期限快到時發mail提醒
        **查看相關連結里程碑、任務 -->
        <!-- 標題 + 匯出 -->
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div>
                <small class="text-muted">
                    共 {{ filtered_requirement.length }} 筆
                </small>
            </div>
            <a href="#" @click.prevent="downloadPDF" class="small">
                匯出當前最低專題需求總覽(初版尚未完整).pdf
            </a>
        </div>

        <!-- 篩選列：沿用原本的 req-filter-row / req-filter-btn 樣式 -->
        <div class="req-filter-row mb-3">
            <span>狀態：</span>

            <button class="req-filter-btn" :class="{ active: filter.requirement_status === '' }"
                @click="filter.requirement_status = ''">
                ALL
            </button>

            <button class="req-filter-btn" :class="{ active: filter.requirement_status === 'notyet' }"
                @click="filter.requirement_status = 'notyet'">
                未回報
            </button>

            <button class="req-filter-btn" :class="{ active: filter.requirement_status === 'taken' }"
                @click="filter.requirement_status = 'taken'">
                審核中
            </button>

            <button class="req-filter-btn" :class="{ active: filter.requirement_status === 'return' }"
                @click="filter.requirement_status = 'return'">
                請修正
            </button>

            <button class="req-filter-btn" :class="{ active: filter.requirement_status === 'done' }"
                @click="filter.requirement_status = 'done'">
                已通過
            </button>
        </div>

        <div class="req-card-list">
            <div class="req-card" v-for="item in filtered_requirement" :key="item.req_ID"
                @click="now_requirement_click(item)">

                <div class="req-color-bar" :style="{ backgroundColor: item.color_hex }"></div>

                <div class="req-card-date">
                    <span class="day vertical-text"> {{ statusText[item.status] || '—' }}</span>
                </div>

                <div class="req-card-main">
                    <!-- 上方：標題 + 狀態 pill -->
                    <div class="req-card-top">
                        <h3 class="req-title">{{ item.req_title }}</h3>

                        <!-- <span class="req-status-pill" :class="'status-' + item.status">
                            {{ statusText[item.status] || '—' }}
                        </span> -->
                    </div>

                    <!-- 中間：說明 -->
                    <div class="req-card-meta">
                        <span>{{ limitDirection(item.req_direction) }}</span>
                    </div>

                    <!-- 量化目標 -->
                    <div class="req-card-meta" v-if="item.req_count && item.req_count.length">
                        <span>量化目標：</span>
                        <span v-for="j in item.req_count" :key="j" class="req-count-tag">
                            {{ j }}
                        </span>
                    </div>
                    <!-- 底部按鈕 -->
                    <button class="req-card-btn" type="button">
                        {{ item.status === 0 ? '前往回報 →' : '詳細資料 →' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 下面是modal -->
<teleport to="body">
    <!-- req -->
    <!-- 查看最低專題需求look req modal -->
    <div class="modal fade" id="req_look_modal" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>最低專題需求詳細資料</h2>
                    <button @click="req_modal_close" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #999; line-height: 1;" class="ms-auto">&times;</button>
                </div>
                <div class="modal-body req-detail-card">
                    <div class="req-detail-header">
                        <h3 class="req-detail-title">{{ now_requirement.req_title }}</h3>
                        <span class="req-count-tag" class="" v-if="now_requirement.status==0">未回報</span>
                        <span class="req-count-tag" v-if="now_requirement.status==1"
                            style="background:#F8BF63">審核中</span>
                        <span class="req-count-tag" v-if="now_requirement.status==2"
                            style="background:#FF775C">請修正</span>
                        <span class="req-count-tag" v-if="now_requirement.status==3"
                            style="background:#CAFCBB">已通過</span>
                    </div>
                    <div class="req-detail-section" v-if="now_requirement.req_direction">
                        <label class="req-detail-label">說明：</label>
                        <p class="req-detail-text">{{ now_requirement.req_direction }}</p>
                    </div>
                    <div class="req-detail-section" v-if="now_requirement.req_count!=''">
                        <label class="req-detail-label">需完成之量化目標：</label>
                        <div class="req-detail-tags">
                            <span class="req-count-chip" v-for="j in now_requirement.req_count">{{ j }}</span>
                        </div>
                    </div>
                    <div class="req-detail-section" v-if="now_requirement.status==0 || req_return_edit">
                        <label class="req-detail-label">回報說明：</label>
                        <textarea class="form-control" v-model="return_form.rp_comment" :readonly="now_requirement.status!=0 && !req_return_edit" rows=4></textarea>
                    </div>

                    <div class="req-detail-section" v-else>
                        <label class="req-detail-label">回報說明：</label>
                        <p class="req-detail-text">{{ return_form.rp_comment }}</p>
                    </div>
                    <div class="req-detail-section" v-if="now_requirement.req_count!=''">
                        <label class="req-detail-label">回報量化數字：</label>
                        <div class="req-detail-tags req-count-row">
                            <div v-for="(j,key) in now_requirement.req_count" class="req-count-item">
                                <span class="req-count-chip" v-if="key!=1">{{ j }}</span>
                                <span class="req-count-chip" v-if="key==1 && !req_return_edit">{{ return_form.count2 }}</span>
                                <input type="number" class="form-control" v-model="return_form.count2" v-if="key==1 && req_return_edit" :readonly="now_requirement.status!=0 || req_return_edit">
                            </div>
                        </div>
                    </div>
                    <div class="req-detail-section" v-if="now_requirement.status!=0">
                        <label class="req-detail-label">最後回報者：{{now_rp.u_name}}</label>
                        <label class="req-detail-label">最後回報時間：{{now_rp.rp_completed_d}}</label>
                        <label class="req-detail-label" v-if="now_rp.rp_approved_d && now_rp.rp_approved_d!='0000-00-00 00:00:00'">最後審核時間：{{now_rp.rp_approved_d}}</label>
                        <div class="req-detail-section" v-if="now_rp.rp_remark">
                            <label class="req-detail-label">指導老師建議：</label>
                            <p class="req-detail-text">{{ now_rp.rp_remark }}</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" @click="req_return_click" v-if="now_requirement.status==0">回報該需求</button>
                    <button class="btn btn-primary" @click="req_return_click_again_show" v-if="!req_return_edit && now_requirement.status!=0">{{now_requirement.status==1 || now_requirement.status==3?'啟用編輯，重新回報，並覆蓋舊回報資料':now_requirement.status==2?'啟用編輯，重新回報':''}}</button>
                    <button class="btn btn-primary" @click="req_return_click_again" v-if="req_return_edit && now_requirement.status!=0">確定送出回報，並覆蓋舊回報資料</button>
                </div>
            </div>
        </div>
    </div>
</teleport>