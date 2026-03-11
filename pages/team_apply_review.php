<!-- pages/team_apply_review.php -->
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// 內容會載入到 main.php 的 #content，路徑需相對於 main.php（專案根目錄）
?>
<!-- team_apply_review.css 已在 head.php 載入 -->
<div id="reviewApp">
  <header class="tar-header">
    <div class="tar-title-wrap">
      <i class="fa-solid fa-user-check tar-title-icon"></i>
      <div class="tar-title">專題指導申請單</div>
    </div>
    <div class="tar-right">
      <template v-if="!selectedForm">
        <button class="tar-btn primary" @click="goCreateForm"><i class="fa-solid fa-plus me-1"></i>新增指導申請單</button>
        <button class="tar-btn" @click="showDeletedView">查看刪除紀錄</button>
      </template>
    </div>
  </header>

  <div class="tar-panel">
    <template v-if="loading">
      <div class="tar-loading">
        <div class="spinner-border"></div>
        <div class="mt-2">載入中…</div>
      </div>
    </template>

    <template v-else-if="showDeleted">
      <div class="tar-muted mb-3" style="font-size:1.15rem">
        <button type="button" class="tar-btn me-2" @click="backFromDeletedView"><i class="fas fa-arrow-left me-1"></i>返回列表</button>
        已刪除的申請單（狀態為刪除）
      </div>
      <template v-if="deletedFormsLoading">
        <div class="tar-loading"><div class="spinner-border"></div></div>
      </template>
      <template v-else-if="deletedForms.length === 0">
        <div class="tar-empty">目前沒有已刪除的申請單</div>
      </template>
      <template v-else>
        <table class="tar-table">
          <thead>
            <tr>
              <th style="width:100px" class="tar-th-center">開放屆別</th>
              <th class="tar-th-center">申請單標題</th>
              <th style="width:90px" class="tar-th-center">狀態</th>
              <th style="width:220px" class="tar-th-center">繳交狀況</th>
              <th style="width:180px" class="tar-th-center">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in deletedForms" :key="f.taf_ID">
              <td>{{ f.cohort_label }}</td>
              <td><b>{{ f.taf_title }}</b></td>
              <td class="tar-status-cell">
                <span class="tar-status off">已刪除</span>
              </td>
              <td class="tar-status-cell">
                <span class="tar-kpi" v-if="f.pending_count !== undefined">待審核 {{ f.pending_count }}</span>
                <span class="tar-kpi" v-if="f.rejected_count !== undefined">退件 {{ f.rejected_count }}</span>
                <span class="tar-kpi" v-if="f.total_count !== undefined">已審核 {{ f.total_count }}</span>
              </td>
              <td>
                <div class="tar-actions">
                  <button type="button" class="tar-link main" @click="restoreForm(f)">復原</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </template>

    <template v-else-if="!selectedForm">
      <div class="tar-muted" style="margin-bottom:10px;font-size:1.15rem">請先選擇要查看的申請單</div>
      <template v-if="formsLoading">
        <div class="tar-loading"><div class="spinner-border"></div></div>
      </template>
      <template v-else-if="forms.length === 0">
        <div class="tar-empty">目前沒有可用表單</div>
      </template> 
      <template v-else>
        <table class="tar-table">
          <thead>
            <tr>
              <th style="width:100px" class="tar-th-center">開放屆別</th>
              <th class="tar-th-center">申請單標題</th>
              <th style="width:90px" class="tar-th-center">狀態</th>
              <th style="width:220px" class="tar-th-center">繳交狀況</th>
              <th style="width:180px" class="tar-th-center">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in forms" :key="f.taf_ID">
              <td>{{ f.cohort_label }}</td>
              <td><b>{{ f.taf_title }}</b></td>
              <td class="tar-status-cell">
                <span class="tar-status" :class="Number(f.taf_status)===1 ? 'on' : 'off'">
                  {{ f.taf_status_label || (Number(f.taf_status)===1 ? '啟用' : '停用') }}
                </span>
              </td>
              <td class="tar-status-cell">
                <span class="tar-kpi" v-if="f.pending_count !== undefined">待審核 {{ f.pending_count }}</span>
                <span class="tar-kpi" v-if="f.rejected_count !== undefined">退件 {{ f.rejected_count }}</span>
                <span class="tar-kpi" v-if="f.total_count !== undefined">已審核 {{ f.total_count }}</span>
              </td>
              <td>
                <div class="tar-actions">
                  <button type="button" class="tar-link main" @click="selectForm(f)">審核</button>
                  <button type="button" class="tar-link" @click="goEditForm(f)">編輯</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </template>

    <template v-else>
      <!-- 老師一眼就懂：精簡 summary + 老師負載 + 申請明細 -->
      <div class="tar-dashboard">
        <div class="tar-dash-header">
          <div class="tar-dash-info">
            <h2 class="tar-dash-title">{{ selectedForm.taf_title }}</h2>
            <p class="tar-dash-sub">
              <span class="tar-dash-cohort">{{ selectedForm.cohort_label }}</span>
              <span class="tar-dash-sep">·</span>
              <span class="tar-inline-edit" @click="openTeacherLimitModal" title="點擊編輯帶組設定">
                <i class="fas fa-cog fa-sm me-1"></i>{{ teacherStatsSummary }}
              </span>
            </p>
          </div>
          <div class="tar-dash-actions">
            <input type="search" class="tar-search" v-model="keyword" placeholder="搜尋專題或老師" @keyup.enter="loadData" aria-label="搜尋">
            <select class="tar-select" v-model="filterStatus" @change="loadData" aria-label="篩選狀態">
              <option value="all">全部狀態</option>
              <option value="1">申請中</option>
              <option value="2">退件</option>
              <option value="3">已審核</option>
            </select>
            <button class="tar-btn" @click="loadData" title="重新載入"><i class="fas fa-sync-alt me-1"></i>重整</button>
            <button class="tar-btn" @click="backToFormList"><i class="fas fa-arrow-left me-1"></i>返回</button>
          </div>
        </div>

        <!-- 統計卡片：可指導 / 申請中 / 已審核 / 剩餘名額 -->
        <div class="tar-summary-row" v-if="teacherStats.length">
          <div class="tar-summary-card">
            <div class="tar-summary-label">可指導老師</div>
            <div class="tar-summary-value">{{ teacherStats.length }}</div>
            <div class="tar-summary-sub">目前可指導的老師數</div>
          </div>
          <div class="tar-summary-card">
            <div class="tar-summary-label">申請中</div>
            <div class="tar-summary-value">{{ listPending }}</div>
            <div class="tar-summary-sub">尚待審核的申請組數</div>
          </div>
          <div class="tar-summary-card">
            <div class="tar-summary-label">已審核</div>
            <div class="tar-summary-value">{{ listTeamed }}</div>
            <div class="tar-summary-sub">已組隊或處理完成</div>
          </div>
          <div class="tar-summary-card tar-summary-highlight">
            <div class="tar-summary-label">剩餘名額</div>
            <div class="tar-summary-value">{{ totalRemaining }}</div>
            <div class="tar-summary-sub">全體老師尚可收的組數</div>
          </div>
        </div>

        <!-- 老師負載：chip 列，點擊可編輯 -->
        <div class="tar-teacher-load" v-if="teacherStats.length">
          <div class="tar-teacher-load-head">
            <span>指導老師帶組數量 / 帶組上限</span>
            <span class="tar-muted" style="font-size:0.85rem">
              <a href="#" @click.prevent="openTeacherLimitModal" class="tar-link-inline"><i class="fas fa-pen fa-xs me-1"></i>編輯</a>
            </span>
          </div>
          <div class="tar-teacher-load-body">
            <template v-for="t in teacherStatsWithStatus" :key="t.u_ID">
              <span class="tar-teacher-chip" :class="'tar-chip-' + t.statusKey">
                <div class="tar-teacher-chip-main">
                  <span>{{ t.u_name }}</span>
                  <span>{{ t.current_count }}/{{ t.max_count ?? '?' }}</span>
                </div>
                <div class="tar-teacher-chip-bar">
                  <div class="tar-teacher-chip-bar-inner" :style="{ width: (t.max_count > 0 ? Math.min(100, Math.round((t.current_count / t.max_count) * 100)) : 0) + '%' }"></div>
                </div>
                <span class="tar-chip-label">{{ t.statusLabel }}</span>
              </span>
            </template>
          </div>
        </div>

        <!-- 重點提醒：精簡三行 -->
        <div class="tar-reminders" v-if="reminderLines.length">
          <span class="tar-reminder-label">提醒：</span>
          <template v-for="(line, i) in reminderLines" :key="i">
            <span class="tar-reminder-item">{{ line }}</span>
          </template>
        </div>
      </div>

      <table class="tar-table">
        <thead>
          <tr>
            <th class="tar-th-center">專題名稱</th>
            <th style="width:180px" class="tar-th-center">指導老師</th>
            <th class="tar-th-center">組員</th>
            <th style="width:200px" class="tar-th-center">申請時間</th>
            <th style="width:120px" class="tar-th-center">狀態</th>
            <th style="width:120px" class="tar-th-center">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="it in list" :key="it.tap_ID" @click="openDetail(it.tap_ID)" style="cursor:pointer">
            <td class="col-project">
              <div><b>{{ it.tap_name || '(未填專題名稱)' }}</b></div>
              <div class="tar-muted" style="font-size:0.85rem;margin-top:2px">{{ it.cohort_label || '-' }}</div>
            </td>
            <td>{{ it.teacher_name || '-' }}</td>
            <td class="text-truncate" style="max-width:420px">{{ it.members_names_text || '-' }}</td>
            <td class="tar-muted">{{ it.apply_time || '-' }}</td>
            <td class="tar-status-cell"><span class="tar-status" :class="statusClass(it.tap_status)">{{ it.status_label }}</span></td>
            <td><button type="button" class="tar-link main" @click.stop="openDetail(it.tap_ID)">查看</button></td>
          </tr>
          <tr v-if="list.length===0">
            <td colspan="7" class="tar-empty">目前沒有資料</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>

  <!-- 帶組設定 Modal：簡化版面、操作更直觀 -->
  <div class="modal" id="teacherLimitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content tar-modal-simple">
        <div class="modal-header">
          <h6 class="modal-title fw-bold"><i class="fa-solid fa-sliders me-2"></i>帶組設定</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
        </div>
        <div class="modal-body">
          <div v-if="teacherStatsLoading" class="text-muted py-4 text-center"><i class="fas fa-spinner fa-spin me-2"></i>載入中…</div>
          <template v-else-if="teacherStats.length">
            <!-- 區塊一：快速設定（成員限制 + 一鍵設定全部） -->
            <div class="tar-modal-quick mb-4">
              <div class="tar-modal-quick-row">
                <div class="tar-modal-quick-item">
                  <span class="tar-modal-quick-label">每組人數</span>
                  <span v-if="editMemberLimit" class="tar-modal-quick-edit">
                    <input type="number" class="form-control form-control-sm" style="width:48px" v-model.number="editMemberMin" min="1">～
                    <input type="number" class="form-control form-control-sm" style="width:48px" v-model.number="editMemberMax" min="1"> 人
                    <button type="button" class="btn btn-sm btn-success ms-1" @click="saveMemberLimit"><i class="fas fa-check"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" @click="editMemberLimit=false"><i class="fas fa-times"></i></button>
                  </span>
                  <span v-else class="tar-modal-quick-value">
                    <strong>{{ memberLimitMin }}～{{ memberLimitMax }} 人</strong>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="startEditMemberLimit" title="編輯"><i class="fas fa-pen"></i></button>
                  </span>
                </div>
                <div class="tar-modal-quick-item">
                  <span class="tar-modal-quick-label">批量設定</span>
                  <div class="d-flex align-items-center gap-2">
                    <input type="number" class="form-control form-control-sm" style="width:56px" v-model.number="batchLimitValue" min="0" placeholder="組">
                    <span class="tar-modal-quick-hint">組</span>
                    <button type="button" class="btn btn-sm btn-primary" @click="batchApplySmart" :disabled="batchLimitValue === '' || batchLimitValue < 0">套用</button>
                  </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="loadTeacherStats" :disabled="teacherStatsLoading" title="重整"><i class="fas fa-sync-alt"></i></button>
              </div>
            </div>
            <!-- 區塊二：老師清單（精簡欄位、清楚標示） -->
            <div class="tar-modal-table-wrap">
              <table class="table table-sm tar-modal-table">
                <thead>
                  <tr>
                    <th style="width:36px"><input type="checkbox" :checked="allTeachersSelected" @change="toggleSelectAll" title="全選"><span class="visually-hidden">全選</span></th>
                    <th>老師</th>
                    <th class="text-center" style="width:72px">帶組</th>
                    <th class="text-center" style="width:72px">申請中</th>
                    <th class="text-center" style="width:100px">上限</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="t in teacherStats" :key="t.u_ID">
                    <td><input type="checkbox" :value="t.u_ID" v-model="selectedTeacherIds"></td>
                    <td><strong>{{ t.u_name }}</strong></td>
                    <td class="text-center">{{ t.current_count }}</td>
                    <td class="text-center">{{ t.apply_count }}</td>
                    <td class="text-center">
                      <span v-if="editLimitTeacher === t.u_ID" class="tar-inline-edit-wrap">
                        <input type="number" class="form-control form-control-sm d-inline-block" style="width:52px" v-model.number="editLimitValue" min="0" @keyup.enter="saveTeacherLimit(t)">
                        <button type="button" class="btn btn-sm btn-success ms-1" @click="saveTeacherLimit(t)"><i class="fas fa-check"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-1" @click="cancelEditLimit"><i class="fas fa-times"></i></button>
                      </span>
                      <span v-else>
                        {{ t.max_count != null ? t.max_count : '—' }}
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1" @click="startEditLimit(t)" title="編輯"><i class="fas fa-pen"></i></button>
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-if="selectedTeacherIds.length > 0" class="tar-modal-hint mt-2 mb-0">
              <small>已勾選 {{ selectedTeacherIds.length }} 位，點「套用」會只更新勾選的老師</small>
            </p>
          </template>
          <div v-else class="text-muted py-4 text-center">尚無指導老師資料</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 詳情 Modal -->
  <div class="modal" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div class="tar-title" style="font-size:20px;flex:1;min-width:0">
            {{ detail.tap_name || '查看申請' }}
            <span class="tar-status" :class="statusClass(detail.tap_status)" style="margin-left:8px">{{ statusText(detail.tap_status) }}</span>
          </div>
          <button type="button" class="tar-modal-close" data-bs-dismiss="modal" aria-label="關閉">×</button>
        </div>

        <div class="modal-body" v-if="detailLoading">
          <div class="tar-loading"><div class="spinner-border"></div></div>
        </div>

        <div class="modal-body" v-else>
          <div class="row g-3">
            <div id="detailImageSection" class="col-12 col-lg-5">
              <div class="tar-image-wrap">
                <div class="tar-muted" style="font-size:16px;margin-bottom:8px">繳交圖片 / 檔案</div>
                <template v-if="detail.image_url">
                  <div v-if="imageLoading" class="tar-image-placeholder">
                    <div class="spinner-border text-primary" role="status"></div>
                    <span>載入中…</span>
                  </div>
                  <template v-else-if="imageBlobUrl">
                    <iframe v-if="isPdfUrl(detail.image_url)" :key="'pdf-'+imageRetryKey" :src="imageBlobUrl + '#page=2'" class="img-fluid rounded border tar-media" style="min-height:500px;width:100%;border:1px solid var(--border)"></iframe>
                    <img v-else :key="'img-'+imageRetryKey" :src="imageBlobUrl" class="img-fluid rounded border tar-media">
                  </template>
                </template>
                <div v-if="!detail.image_url || imageLoadFailed" class="tar-image-placeholder">
                  <i class="fa-solid fa-file-image"></i>
                  <span>{{ detail.image_url && imageLoadFailed ? '圖片載入失敗' : '無繳交圖片' }}</span>
                  <button v-if="detail.image_url && imageLoadFailed" type="button" class="btn btn-sm btn-outline-primary mt-2" @click="retryImage">
                    <i class="fa-solid fa-rotate-right me-1"></i>重新載入
                  </button>
                </div>
              </div>
            </div>
            <div class="col-12 col-lg-7">
              <div class="tar-grid">
                <div class="tar-muted">屆別</div><div><b>{{ detail.cohort_label || '-' }}</b></div>
                <div class="tar-muted">提交人</div><div>{{ detail.submitter_name }}（{{ detail.tap_u_ID }}）</div>
                <div class="tar-muted">指導老師</div>
                <div>
                  {{ detail.teacher_name || '-' }}
                  <div v-if="detail.teacher_id" class="tar-muted" style="font-size:14px;margin-top:4px">
                    帶組：<b>{{ detail.teacher_current_count ?? 0 }}</b> / <b>{{ detail.teacher_max_count ?? '?' }}</b>
                    ，申請中：<b>{{ detail.teacher_pending_count ?? 0 }}</b> 組
                  </div>
                </div>
                <div class="tar-muted">組員</div><div>{{ (detail.members_names||[]).join('、') || '-' }}</div>
              </div>
              <div style="border:1px solid var(--border);border-radius:10px;padding:10px;margin-bottom:12px;background:#fafafa">
                <div class="tar-muted" style="font-size:16px;margin-bottom:6px">提交人備註</div>
                <div style="white-space:pre-wrap">{{ detail.submitter_comment || '（無）' }}</div>
              </div>
              <div style="font-weight:900;margin-bottom:6px">審核人備註</div>
              <textarea class="form-control" rows="4" v-model="detail.tap_remark" style="font-size:18px;padding:10px;border-radius:10px"></textarea>
              <div class="tar-muted" style="font-size:16px;margin-top:6px">儲存備註不關閉；通過/退件會關閉。</div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button class="tar-btn" @click="saveRemark" :disabled="!detail.tap_ID">儲存備註</button>
          <button v-if="detail && detail.tap_status==1" class="tar-btn primary" @click="approve">通過</button>
          <button v-if="detail && detail.tap_status==1" class="tar-btn" style="border-color:var(--danger);color:var(--danger)" @click="reject">退件</button>
          <button class="tar-btn" data-bs-dismiss="modal">關閉</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="js/team_apply_review.js?v=<?= time() ?>"></script>
