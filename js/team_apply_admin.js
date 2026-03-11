// js/team_apply_admin.js
(function () {
  // SPA：避免重複綁定
  if (window.__teamApplyAdminBound) return;
  window.__teamApplyAdminBound = true;

  document.addEventListener('page:loaded', function (e) {
    const path = e?.detail?.path || '';
    if (!path.includes('pages/team_apply_admin.php')) return;
    init();
  });

  function init() {
    const mountEl = document.querySelector('#content #adminApp');
    if (!mountEl) return;

    if (!window.Vue || !Vue.createApp) {
      console.warn('Vue not found');
      return;
    }

    // 卸載舊 app（換頁回來不會疊加）
    if (window._teamApplyAdminApp && typeof window._teamApplyAdminApp.unmount === 'function') {
      try { window._teamApplyAdminApp.unmount(); } catch (_) {}
      window._teamApplyAdminApp = null;
    }

    const ADMIN_API = 'modules/team_apply_admin_api.php';

    window._teamApplyAdminApp = Vue.createApp({
      data() {
        return {
          q: '',
          loading: { forms:false, detail:false },
          toast: { show:false, text:'' },

          cohorts: [],
          forms: [],
          selFormId: null,

          // 右側：建立表單（未選表時使用）
          create: {
            taf_title: '',
            taf_cohort_ID: '',
            taf_ttl: 1,
            min_count: 1,
            max_count: 4
          },

          // 右側：選中表單的詳細設定
          formEdit: {},
          controls: [],
          removedControls: [],
          tableColumns: [],
          limits: [],
          limitEdit: { ttm_ID:0, cohort_ID:'', min_count:1, max_count:4 },

          newControl: { name: '' }
        };
      },

      computed: {
        activeCohorts() {
          return (this.cohorts || []).filter(c => c && (Number(c.cohort_status) === 0 || Number(c.cohort_status) === 1));
        },
        filteredForms() {
          const q = (this.q || '').trim().toLowerCase();
          if (!q) return this.forms || [];
          return (this.forms || []).filter(f => {
            const title = String(f.taf_title || '').toLowerCase();
            const cohortName = String(this.getCohortName(f.taf_cohort_ID) || '').toLowerCase();
            return title.includes(q) || cohortName.includes(q) || String(f.taf_cohort_ID||'').includes(q);
          });
        }
      },

      async mounted() {
        await this.reloadAll();
      },

      methods: {
        // ---------- UX ----------
        goBack(){
          // 若從 team_apply_review 來，回上一頁；否則導向審核列表
          if (window.history.length > 1) {
            window.history.back();
          } else {
            window.location.hash = 'pages/team_apply_review.php';
          }
        },
        tip(msg){
          this.toast.text = msg;
          this.toast.show = true;
          clearTimeout(this.__toastTimer);
          this.__toastTimer = setTimeout(()=>{ this.toast.show=false; }, 1800);
        },

        // ---------- helpers ----------
        getCohortName(id){
          const c = (this.cohorts||[]).find(x => Number(x.cohort_ID) === Number(id));
          return c ? (c.cohort_name || String(c.cohort_ID)) : '';
        },

        // ---------- load ----------
        getTafIdFromUrl(){
          try {
            const hash = window.location.hash || '';
            const qpos = hash.indexOf('?');
            if (qpos >= 0) {
              const qs = new URLSearchParams(hash.slice(qpos + 1));
              const id = parseInt(qs.get('taf_ID') || '0', 10);
              if (id > 0) return id;
            }
            const search = window.location.search || '';
            if (search) {
              const qs = new URLSearchParams(search);
              const id = parseInt(qs.get('taf_ID') || '0', 10);
              if (id > 0) return id;
            }
          } catch (_) {}
          return null;
        },

        async reloadAll(){
          await this.loadCohorts();
          await this.loadForms();
          const urlTafId = this.getTafIdFromUrl();
          if (urlTafId && this.forms && this.forms.length) {
            const f = this.forms.find(x => Number(x.taf_ID) === urlTafId);
            if (f) await this.openForm(f);
            else this.closeForm();
          } else {
            this.closeForm();
          }
        },

        async reloadDetail(){
          if (!this.selFormId) return;
          await this.loadControls();
        //   await this.loadLimits();
          await this.reloadTableColumns();
          this.tip('已重整此表');
        },

        async loadCohorts(){
          const res = await fetch(ADMIN_API + '?do=get_cohort_options', { credentials:'same-origin' });
          const txt = await res.text();

          // 防呆：如果回傳 <br> 代表 PHP fatal 或 warning（不是 JSON）
          if (txt.trim().startsWith('<')) {
            console.error('API returned HTML:', txt);
            alert('get_cohort_options 回傳不是 JSON，請先修 API（可能有 PHP error）');
            return;
          }

          const j = JSON.parse(txt);
          if (!j.ok) { alert(j.msg || '載入屆別失敗'); return; }
          this.cohorts = j.cohorts || [];
        },

        async loadForms(){
          this.loading.forms = true;
          try{
            const res = await fetch(ADMIN_API + '?do=admin_get_forms', { credentials:'same-origin' });
            const j = await res.json();
            if (!j.ok) { alert(j.msg || '載入表單失敗'); return; }
            this.forms = j.forms || [];
          } finally {
            this.loading.forms = false;
          }
        },

        async loadControls(){
          const res = await fetch(ADMIN_API + '?do=admin_get_controls&taf_ID=' + encodeURIComponent(this.selFormId));
          const j = await res.json();
          if (!j.ok) { alert(j.msg || '載入控制項失敗'); return; }
          // checkbox 用 boolean 會比較直覺
          this.controls = (j.controls || []).map(x => ({
            ...x,
            tpc_require: Number(x.tpc_require) === 1,
            tpc_show: Number(x.tpc_show) === 1
          }));
          this.removedControls = [];
        },

        async loadLimits(){
          const res = await fetch(ADMIN_API + '?do=admin_get_limits');
          const j = await res.json();
          if (!j.ok) { alert(j.msg || '載入人數規則失敗'); return; }
          this.limits = j.limits || [];
        },

        async reloadTableColumns(){
          const res = await fetch(ADMIN_API + '?do=admin_get_table_comments&table=teamapply');
          const j = await res.json();
          if (!j.ok) return;
          this.tableColumns = j.columns || [];
        },

        colComment(colName){
          const labelMap = { tap_group: '類組' };
          if (labelMap[colName]) return labelMap[colName];
          const c = (this.tableColumns || []).find(x => x.COLUMN_NAME === colName);
          return c ? (c.COLUMN_COMMENT || '') : '';
        },

        previewFieldShow(key){
          const c = (this.controls || []).find(x => String(x.tpc_name) === String(key));
          return c ? !!c.tpc_show : false;
        },
        previewFieldRequire(key){
          const c = (this.controls || []).find(x => String(x.tpc_name) === String(key));
          return c ? !!c.tpc_require : false;
        },

        // ---------- navigation ----------
        closeForm(){
          this.selFormId = null;
          this.formEdit = {};
          this.controls = [];
          this.removedControls = [];
          this.newControl.name = '';
        },

        async openForm(f){
          this.selFormId = Number(f.taf_ID);
          // 複製一份可編輯資料
          this.formEdit = {
            ...f,
            taf_cohort_ID: Number(f.taf_cohort_ID),
            taf_status: Number(f.taf_status ?? 1),
            min_count: Number(f.min_count ?? 1),
            max_count: Number(f.max_count ?? 4)
          };
          await this.reloadDetail();
        },

        startCreate(){
          this.closeForm();
          // 滾到右側建立區（小 UX）
          setTimeout(() => {
            const rightCard = document.querySelector('#content #adminApp .ta-grid');
            if (rightCard) rightCard.scrollIntoView({ behavior:'smooth', block:'start' });
          }, 0);
        },

        // ---------- actions ----------
        async saveCreate(){
          const p = this.create;

          if (!p.taf_title || !String(p.taf_title).trim()) return alert('請輸入表單名稱');
          if (!p.taf_cohort_ID) return alert('請選擇屆別');
          if (Number(p.min_count) > Number(p.max_count)) return alert('最小人數不可大於最大人數');

          const fd = new FormData();
          fd.append('taf_ID', 0);
          fd.append('taf_title', String(p.taf_title).trim());
          fd.append('taf_cohort_ID', p.taf_cohort_ID);
          fd.append('taf_ttl', p.taf_ttl ?? 1);
          fd.append('taf_status', 1);
          fd.append('taf_note', '');

          fd.append('min_count', p.min_count ?? 1);
          fd.append('max_count', p.max_count ?? 4);

          const res = await fetch(ADMIN_API + '?do=admin_save_form', { method:'POST', body: fd });
          const j = await res.json();
          if (!j.ok) return alert(j.msg || '建立失敗');

          this.tip('已建立表單');
          await this.loadForms();

          // 建立完自動選到該筆（讓使用者順著流程進去設定欄位）
          const created = (this.forms||[]).find(x => Number(x.taf_ID) === Number(j.taf_ID));
          if (created) await this.openForm(created);
        },

        async saveFormEdit(){
          if (!this.selFormId) return;
          const f = this.formEdit;

          if (!f.taf_title || !String(f.taf_title).trim()) return alert('請輸入表單名稱');
          if (!f.taf_cohort_ID) return alert('請選擇屆別');
          if (Number(f.min_count) > Number(f.max_count)) return alert('最小人數不可大於最大人數');

          const fd = new FormData();
          fd.append('taf_ID', this.selFormId);
          fd.append('taf_title', String(f.taf_title).trim());
          fd.append('taf_cohort_ID', f.taf_cohort_ID);
          fd.append('taf_ttl', f.taf_ttl ?? '');
          fd.append('taf_status', f.taf_status ?? 1);
          fd.append('taf_note', f.taf_note ?? '');

          fd.append('min_count', f.min_count ?? 1);
          fd.append('max_count', f.max_count ?? 4);

          const res = await fetch(ADMIN_API + '?do=admin_save_form', { method:'POST', body: fd });
          const j = await res.json();
          if (!j.ok) return alert(j.msg || '儲存失敗');

          this.tip('已儲存基本設定');
          await this.loadForms(); // 更新左側列表顯示
        },
        removeControl(c) {
          if (!c) return;
          this.removedControls.push({ ...c });
          // 只從畫面移除，不呼叫 API、不改資料庫
          this.controls = (this.controls || []).filter(x => Number(x.tpc_ID) !== Number(c.tpc_ID));
          this.tip('已從畫面清單移除');
        },
        async saveControl(c){
          const fd = new FormData();
          fd.append('tpc_ID', c.tpc_ID);
          fd.append('tpc_name', c.tpc_name);
          fd.append('tpc_require', c.tpc_require ? 1 : 0);
          fd.append('tpc_show', c.tpc_show ? 1 : 0);
          fd.append('tpc_taf_ID', this.selFormId);

          const res = await fetch(ADMIN_API + '?do=admin_save_control', { method:'POST', body: fd });
          const j = await res.json();
          if (!j.ok) return alert(j.msg || '更新失敗');

          this.tip('已更新欄位');
          await this.loadControls();
        },

        async addControl(){
          if (!this.newControl.name) return alert('請選擇欄位');
          if (!this.selFormId) return alert('請先選擇表單');

          const existsOnScreen = (this.controls || []).some(x => String(x.tpc_name) === String(this.newControl.name));
          if (existsOnScreen) return alert('此欄位已在清單中');

          const removedIdx = (this.removedControls || []).findIndex(x => String(x.tpc_name) === String(this.newControl.name));
          if (removedIdx !== -1) {
            const restored = this.removedControls.splice(removedIdx, 1)[0];
            this.controls.push(restored);
            this.newControl.name = '';
            this.tip('已加回清單');
            return;
          }

          const fd = new FormData();
          fd.append('tpc_ID', 0);
          fd.append('tpc_name', this.newControl.name);
          fd.append('tpc_require', 0);
          fd.append('tpc_show', 1);
          fd.append('tpc_taf_ID', this.selFormId);

          const res = await fetch(ADMIN_API + '?do=admin_save_control', { method:'POST', body: fd });
          const j = await res.json();
          if (!j.ok) return alert(j.msg || '新增失敗');

          this.newControl.name = '';
          this.tip('已加入欄位');
          await this.loadControls();
        },

        editLimit(lim){
          this.limitEdit = {
            ttm_ID: Number(lim.ttm_ID),
            cohort_ID: Number(lim.cohort_ID),
            min_count: Number(lim.min_count),
            max_count: Number(lim.max_count)
          };
        },

        async saveLimit(){
          const p = this.limitEdit;
          if (!p.cohort_ID) return alert('請選擇屆別');
          if (Number(p.min_count) > Number(p.max_count)) return alert('最小人數不可大於最大人數');

          const fd = new FormData();
          fd.append('ttm_ID', p.ttm_ID ?? 0);
          fd.append('cohort_ID', p.cohort_ID);
          fd.append('min_count', p.min_count ?? 1);
          fd.append('max_count', p.max_count ?? 4);

          const res = await fetch(ADMIN_API + '?do=admin_save_limit', { method:'POST', body: fd });
          const j = await res.json();
          if (!j.ok) return alert(j.msg || '儲存失敗');

          this.tip('已儲存規則');
          // 清空編輯器
          this.limitEdit = { ttm_ID:0, cohort_ID:'', min_count:1, max_count:4 };
        //   await this.loadLimits();
        }
      }
    }).mount(mountEl);
  }
})();
