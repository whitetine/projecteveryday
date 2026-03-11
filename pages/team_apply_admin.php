<?php
// pages/team_apply_admin.php
session_start();
if (empty($_SESSION['u_ID'])) { header('Location: ../index.php'); exit; }
?>

<style>
  /* 防止 Vue 尚未掛載時顯示 {{ 變數 }} 閃爍 */
  [v-cloak] { display: none !important; }
  /* ===== Admin UX layout (RWD) ===== */
  #adminApp { width: 100%; max-width: 1200px; margin: 16px auto; padding: 0 16px; box-sizing: border-box; }
  .ta-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom: 12px; flex-wrap: wrap; }
  .ta-title { font-weight: 800; font-size: 1.25rem; margin: 0; }

  .ta-grid { display: block; width: 100%; box-sizing: border-box; }

  .ta-card { border: 1px solid rgba(0,0,0,.08); border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); box-sizing: border-box; }
  .ta-card-hd { padding: 12px 14px; border-bottom: 1px solid rgba(0,0,0,.06); display:flex; align-items:center; justify-content:space-between; gap: 10px; font-size: 1.35rem; font-weight: 700; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .ta-toolbar-row { display: flex; align-items: center; gap: 10px; flex-wrap: nowrap; min-width: min-content; }
  .ta-form-select { flex: 1 1 auto; min-width: 0; max-width: 100%; }
  .flex-shrink-0 { flex-shrink: 0 !important; }
  .ta-card-hd .ta-card-title { font-size: 1.35rem; font-weight: 700; }
  .ta-card-bd { padding: 14px; }
  #adminApp .ta-card .form-label { font-size: 1.05rem; }
  #adminApp .ta-card .form-control,
  #adminApp .ta-card .form-select { font-size: 1.05rem; }

  .ta-list { max-height: calc(100vh - 210px); overflow:auto; }
  .ta-item { padding: 10px 12px; border-radius: 10px; cursor:pointer; border: 1px solid transparent; }
  .ta-item:hover { background: rgba(0,0,0,.03); }
  .ta-item.active { background: rgba(13,110,253,.08); border-color: rgba(13,110,253,.25); }
  .ta-item-title { font-weight: 700; margin:0; font-size: 1.1rem; }
  .ta-item-sub { margin: 4px 0 0; font-size: 0.95rem; color: rgba(0,0,0,.6); display:flex; gap:8px; flex-wrap:wrap; }

  .ta-badge { font-size: 1.1rem; padding: 6px 12px; border-radius: 999px; border: 1px solid rgba(0,0,0,.1); background: rgba(0,0,0,.02); }
  .ta-badge.ok { border-color: rgba(25,135,84,.25); background: rgba(25,135,84,.08); }
  .ta-badge.off { border-color: rgba(220,53,69,.25); background: rgba(220,53,69,.08); }

  .ta-muted { color: rgba(0,0,0,.6); font-size: 13px; }

  .ta-kv { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
  @media (max-width: 992px){ .ta-kv { grid-template-columns: 1fr; } }

  .ta-table th { white-space: nowrap; }
  .sticky-actions { position: sticky; top: 10px; z-index: 1; background: transparent; font-size: 1.05rem; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .sticky-actions .ta-toolbar-row { flex: 1 1 auto; min-width: min-content; }
  .sticky-actions .btn { font-size: 1rem; flex-shrink: 0; }
  .ta-form-name { font-size: 1.5rem; font-weight: 700; }

  /* small toast */
  .ta-toast {
    position: fixed; right: 18px; bottom: 18px;
    padding: 10px 12px; border-radius: 10px;
    background: rgba(0,0,0,.85); color:#fff; font-size: 13px;
    opacity: 0; transform: translateY(6px);
    transition: .18s ease; pointer-events:none;
  }
  .ta-toast.show { opacity: 1; transform: translateY(0); }
  /* ===== Custom table (no bootstrap table) ===== */
.ta-gridtable{
  border: 1px solid rgba(0,0,0,.08);
  border-radius: 12px;
  overflow: hidden;
  background: #fff;
}

.ta-gridtable .gt-row{
  display: grid;
  grid-template-columns: 1fr 100px 100px;
  gap: 0;
  align-items: center;
}

.ta-gridtable .gt-head{
  background: rgba(0,0,0,.03);
  font-weight: 800;
  font-size: 1.15rem;
  color: rgba(0,0,0,.72);
  border-bottom: 1px solid rgba(0,0,0,.08);
}

.ta-gridtable .gt-head > div{
  padding: 14px 16px;
}

.ta-gridtable .gt-body .gt-row{
  border-bottom: 1px solid rgba(0,0,0,.06);
}

.ta-gridtable .gt-body .gt-row:last-child{
  border-bottom: 0;
}

.ta-gridtable .gt-body .gt-row:hover{
  background: rgba(13,110,253,.04);
}

.ta-gridtable .gt-body > .gt-row > div{
  padding: 14px 16px;
  font-size: 1.15rem;
}

/* first column */
.ta-gridtable .gt-colname .title{
  font-weight: 800;
  font-size: 1.2rem;
  margin: 0;
  line-height: 1.3;
}

.ta-gridtable .gt-colname .sub{
  margin: 4px 0 0;
  font-size: 12px;
  color: rgba(0,0,0,.55);
}

/* checkbox: simple, consistent */
.ta-check{
  width: 20px;
  height: 20px;
  accent-color: #0d6efd; /* 需要 Chrome/Edge 支援 */
  cursor: pointer;
}

/* action button: smaller & cleaner */
.ta-action{
  border: 1px solid rgba(0,0,0,.18);
  background: #fff;
  border-radius: 10px;
  padding: 6px 10px;
  font-size: 14px;
}

.ta-action:hover{
  background: rgba(0,0,0,.04);
}

.ta-action:active{
  transform: translateY(1px);
}

/* RWD 響應式：保持工具列同一行，窄螢幕可水平捲動 */
@media (max-width: 992px) {
  #adminApp { padding: 0 12px; }
  .ta-form-name { font-size: 1.25rem; }
  .ta-badge { font-size: 0.95rem; padding: 5px 10px; }
}

@media (max-width: 768px) {
  #adminApp { padding: 0 12px; }
  .ta-card-hd { padding: 10px 12px; }
  .ta-card-bd { padding: 12px; }
  .ta-form-name { font-size: 1.1rem; }
  .ta-badge { font-size: 0.9rem; padding: 4px 8px; }
  .ta-gridtable .gt-row { grid-template-columns: 1fr 70px 70px; }
  .ta-gridtable .gt-head > div,
  .ta-gridtable .gt-body > .gt-row > div { padding: 12px 10px; font-size: 1.05rem; }
  .ta-gridtable .gt-colname .title { font-size: 1.1rem; }
  .ta-gridtable .gt-colname .sub { font-size: 12px; }
  /* 基本設定區：小螢幕改為單欄 */
  .ta-card .row .col-4 { flex: 0 0 100%; max-width: 100%; }
  .ta-card .row .col-6 { flex: 0 0 100%; max-width: 100%; }
}

@media (max-width: 576px) {
  #adminApp { padding: 0 10px; margin: 12px auto; }
  .ta-card-hd { padding: 8px 10px; }
  .ta-card-bd { padding: 10px; }
  .ta-form-name { font-size: 1rem; }
  .ta-badge { font-size: 0.85rem; padding: 4px 8px; }
  .ta-gridtable .gt-row { grid-template-columns: 1fr 60px 60px; }
  .ta-gridtable .gt-head > div,
  .ta-gridtable .gt-body > .gt-row > div { padding: 10px 8px; font-size: 1rem; }
  .ta-toast { right: 10px; bottom: 10px; left: 10px; text-align: center; }
}

</style>

<div id="adminApp" v-cloak>
  <div class="ta-grid">
    <!-- 表單設定（全寬） -->
    <div class="ta-card">
      <div class="ta-card-hd">
        <div class="ta-toolbar-row">
          <button class="btn btn-outline-secondary btn-sm flex-shrink-0" @click="goBack">
            <i class="fas fa-arrow-left me-1"></i>上一頁
          </button>
        </div>
      </div>

      <div class="ta-card-bd">
        <!-- 未選表：顯示建立/說明 -->
        <div v-if="!selFormId">
          <!-- <div class="alert alert-light border">
            <div class="fw-bold mb-1">先從左邊選擇一張表單</div>
            <div class="ta-muted">選擇後才會顯示「欄位控制 / 人數規則」。</div>
          </div> -->

          <div class="ta-card" style="border-radius:12px;">
            <div class="ta-card-hd">
              <div class="fw-bold">申請單</div>
              <div class="ta-muted">建立後可從上方下拉選單選擇編輯</div>
            </div>
            <div class="ta-card-bd">
              <div class="mb-2">
                <label class="form-label mb-1">表單名稱</label>
                <input class="form-control" v-model.trim="create.taf_title" placeholder="例如：110屆指導申請單">
              </div>

              <div class="mb-2">
                <label class="form-label mb-1">屆別（單選）</label>
                <select class="form-select" v-model.number="create.taf_cohort_ID">
                  <option value="">-- 請選擇屆別 --</option>
                  <option v-for="c in activeCohorts" :key="c.cohort_ID" :value="Number(c.cohort_ID)">
                    {{ c.cohort_name }}
                  </option>
                </select>
              </div>

              <div class="ta-kv mb-2">
                <div>
                  <label class="form-label mb-1">老師帶組上限</label>
                  <input class="form-control" type="number" min="1" v-model.number="create.taf_ttl">
                </div>
                <div>
                  <label class="form-label mb-1">成員最低人數</label>
                  <input class="form-control" type="number" min="1" v-model.number="create.min_count">
                </div>
                <div>
                  <label class="form-label mb-1">成員最高人數</label>
                  <input class="form-control" type="number" min="1" v-model.number="create.max_count">
                </div>
              </div>

              <div class="d-flex gap-2 align-items-center">
                <button class="btn btn-primary" @click="saveCreate">儲存申請單</button>
                <button class="btn btn-outline-secondary btn-sm" type="button" @click="goBack"><i class="fas fa-arrow-left me-1"></i>回上一頁</button>
              </div>
            </div>
          </div>
        </div>

        <!-- 已選表：顯示 Step2/Step3 -->
        <div v-else>
          <div class="sticky-actions mb-3">
            <div class="ta-toolbar-row d-flex gap-2 align-items-center">
              <div class="fw-bold me-2 ta-form-name">{{ formEdit.taf_title }}</div>
              <span class="ta-badge">{{ getCohortName(formEdit.taf_cohort_ID) }}</span>
              <span class="ta-badge" :class="Number(formEdit.taf_status)===1 ? 'ok' : 'off'">
                {{ Number(formEdit.taf_status)===1 ? '啟用' : '停用' }}
              </span>
              <button class="btn btn-primary btn-sm ms-auto" @click="saveFormEdit">儲存基本設定</button>
              <button class="btn btn-outline-secondary btn-sm flex-shrink-0" type="button" @click="goBack"><i class="fas fa-arrow-left me-1"></i>回上一頁</button>
            </div>
          </div>

          <div class="row g-3">
            <!-- Step 1：基本設定（左） -->
            <div class="col-lg-5">
          <div class="ta-card mb-3 h-100">
            <div class="ta-card-hd"><div class="fw-bold">基本設定</div></div>
            <div class="ta-card-bd">
              <div class="row g-2">
                <div class="col-12">
                  <label class="form-label mb-1">表單名稱</label>
                  <input class="form-control" v-model.trim="formEdit.taf_title">
                </div>
                <div class="col-6">
                  <label class="form-label mb-1">屆別</label>
                  <select class="form-select" v-model.number="formEdit.taf_cohort_ID">
                    <option v-for="c in activeCohorts" :key="c.cohort_ID" :value="Number(c.cohort_ID)">
                      {{ c.cohort_name }}
                    </option>
                  </select>
                </div>
                <div class="col-6">
                  <label class="form-label mb-1">狀態</label>
                  <select class="form-select" v-model.number="formEdit.taf_status">
                    <option :value="1">啟用</option>
                    <option :value="0">停用</option>
                  </select>
                </div>

                <div class="col-4">
                  <label class="form-label mb-1">老師帶組上限</label>
                  <input class="form-control" type="number" min="1" v-model.number="formEdit.taf_ttl">
                </div>
                <div class="col-4">
                  <label class="form-label mb-1">成員最低人數</label>
                  <input class="form-control" type="number" min="1" v-model.number="formEdit.min_count">
                </div>
                <div class="col-4">
                  <label class="form-label mb-1">成員最高人數</label>
                  <input class="form-control" type="number" min="1" v-model.number="formEdit.max_count">
                </div>

                <div class="col-12">
                  <label class="form-label mb-1">表單備註</label>
                  <textarea class="form-control" rows="2" v-model.trim="formEdit.taf_note" placeholder="顯示在表單上方的提示文字..."></textarea>
                </div>
              </div>
            </div>
          </div>
            </div>

            <!-- Step 2：欄位顯示控制（右） -->
            <div class="col-lg-7">
          <div class="ta-card mb-3 h-100">
            <div class="ta-card-hd">
              <div class="fw-bold">欄位顯示控制（顯示 / 必填）</div>
            </div>
            <div class="ta-card-bd">
              <div class="table-responsive">
<div class="ta-gridtable">
  <div class="gt-row gt-head">
    <div>欄位</div>
    <div class="text-center">必填</div>
    <div class="text-center">顯示</div>
  </div>

  <div class="gt-body">
    <div class="gt-row" v-for="c in controls" :key="c.tpc_ID">
      <div class="gt-colname">
        <div class="title">{{ colComment(c.tpc_name) || c.tpc_name }}</div>
      </div>
      <div class="text-center">
        <input class="ta-check" type="checkbox" v-model="c.tpc_require" @change="saveControl(c)">
      </div>
      <div class="text-center">
        <input class="ta-check" type="checkbox" v-model="c.tpc_show" @change="saveControl(c)">
      </div>
    </div>

    <div class="gt-row" v-if="controls.length===0">
      <div class="ta-muted" style="padding:14px 16px; grid-column: 1 / -1; font-size: 1.15rem;">
        尚無控制項。
      </div>
    </div>
  </div>
</div>

              </div>
            </div>
          </div>
            </div>
          </div><!-- end row Step1+Step2 -->

          <!-- 申請單預覽：儲存後 team_apply 顯示的樣子 -->
          <div class="ta-card mt-4">
            <div class="ta-card-hd">
              <div class="fw-bold"><i class="fas fa-eye me-2"></i>申請單預覽</div>
              <div class="ta-muted" style="font-size:0.9rem;font-weight:normal">儲存後，學生填寫時會看到以下樣貌</div>
            </div>
            <div class="ta-card-bd">
              <div class="ta-preview-wrap" style="max-width:700px;margin:0 auto">
                <div class="card shadow-sm border">
                  <div class="card-header bg-primary text-white p-3">
                    <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>{{ formEdit.taf_title || '專題指導申請單' }}</h5>
                  </div>
                  <div class="card-body p-4">
                    <div v-if="formEdit.taf_note" class="alert alert-light mb-3">
                      <strong>說明：</strong> {{ formEdit.taf_note }}
                    </div>
                    <div class="ta-preview-form">
                      <div v-if="previewFieldShow('tap_name')" class="mb-3">
                        <label class="form-label" :class="{ 'text-danger': previewFieldRequire('tap_name') }">專題名稱 <span v-if="previewFieldRequire('tap_name')">*</span></label>
                        <input type="text" class="form-control" disabled placeholder="請輸入完整的專題題目" style="background:#f8f9fa">
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3" v-if="previewFieldShow('tap_teacher')">
                          <label class="form-label" :class="{ 'text-danger': previewFieldRequire('tap_teacher') }">指導老師 <span v-if="previewFieldRequire('tap_teacher')">*</span></label>
                          <select class="form-select" disabled style="background:#f8f9fa"><option>請選擇指導老師</option></select>
                        </div>
                        <div class="col-md-6 mb-3" v-if="previewFieldShow('tap_teacher_2')">
                          <label class="form-label" :class="{ 'text-danger': previewFieldRequire('tap_teacher_2') }">指導老師-2 <span v-if="previewFieldRequire('tap_teacher_2')">*</span></label>
                          <select class="form-select" disabled style="background:#f8f9fa"><option>無</option></select>
                        </div>
                        <div class="col-md-6 mb-3" v-if="previewFieldShow('tap_teacher_3')">
                          <label class="form-label" :class="{ 'text-danger': previewFieldRequire('tap_teacher_3') }">指導老師-3 <span v-if="previewFieldRequire('tap_teacher_3')">*</span></label>
                          <select class="form-select" disabled style="background:#f8f9fa"><option>無</option></select>
                        </div>
                        <div class="col-md-6 mb-3" v-if="previewFieldShow('tap_group')">
                          <label class="form-label" :class="{ 'text-danger': previewFieldRequire('tap_group') }">類組 <span v-if="previewFieldRequire('tap_group')">*</span></label>
                          <select class="form-select" disabled style="background:#f8f9fa"><option>請選擇類組</option></select>
                        </div>
                      </div>
                      <div class="mb-3" v-if="previewFieldShow('tap_co_teacher')">
                        <label class="form-label">副指導老師 (選填)</label>
                        <select class="form-select" disabled style="background:#f8f9fa"><option>無</option></select>
                      </div>
                      <div class="mb-3" v-if="previewFieldShow('tap_member')">
                        <label class="form-label" :class="{ 'text-danger': previewFieldRequire('tap_member') }">組別成員 (含申請人最多{{ formEdit.max_count || 4 }}人) <span v-if="previewFieldRequire('tap_member')">*</span></label>
                        <div class="text-muted small p-2 border border-dashed rounded bg-light">請輸入組員學號並點擊新增</div>
                      </div>
                      <div class="mb-3" v-if="previewFieldShow('tap_url')">
                        <label class="form-label" :class="{ 'text-danger': previewFieldRequire('tap_url') }">申請表照片 (需有老師簽名) <span v-if="previewFieldRequire('tap_url')">*</span></label>
                        <div class="text-muted small p-2 border border-dashed rounded bg-light">尚未選擇圖片</div>
                      </div>
                      <div class="mb-3" v-if="previewFieldShow('tap_des')">
                        <label class="form-label">備註說明</label>
                        <textarea class="form-control" rows="2" disabled placeholder="有其他事項請在此說明..." style="background:#f8f9fa"></textarea>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div><!-- end selected -->
      </div>
    </div>
  </div>
</div>

<div class="ta-toast" :class="{show: toast.show}">{{ toast.text }}</div>

<script src="js/team_apply_admin.js?v=<?= time() ?>"></script>