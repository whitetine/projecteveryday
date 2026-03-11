/**
 * 專題申請審核 - Vue App（乾淨版）
 */
(function () {
  if (window.__teamApplyReviewApp && typeof window.__teamApplyReviewApp.unmount === 'function') {
    try { window.__teamApplyReviewApp.unmount(); } catch (e) {}
    window.__teamApplyReviewApp = null;
  }

  if (!window.Vue || !Vue.createApp) {
    console.error('Vue 尚未載入');
    return;
  }

  // API 路徑：main.php 載入時用 modules/；若在 pages/ 下則用 ../
  const getApiPath = () => {
    const p = (window.location.pathname || '');
    return p.includes('/pages/') ? '../modules/team_apply_review_api.php' : 'modules/team_apply_review_api.php';
  };
  const API = getApiPath();

  const Toast = Swal.mixin({
    toast: true,
    position: 'bottom-end',
    showConfirmButton: false,
    timer: 1400,
    timerProgressBar: true
  });

  const app = Vue.createApp({
    data() {
      return {
        forms: [],
        formsLoading: false,
        selectedForm: null,
        list: [],
        loading: false,
        filterStatus: 'all',
        keyword: '',
        detail: {},
        detailLoading: false,
        modal: null,
        modalTeacherLimit: null,
        imageLoadFailed: false,
        imageRetryKey: 0,
        imageUseDirectPath: false,
        imageBlobUrl: null,
        imageLoading: false,
        teacherStats: [],
        teacherStatsLoading: false,
        editLimitTeacher: null,
        editLimitValue: 0,
        memberLimitMin: 1,
        memberLimitMax: 4,
        editMemberLimit: false,
        editMemberMin: 1,
        editMemberMax: 4,
        batchLimitValue: '',
        selectedTeacherIds: [],
        showDeleted: false,
        deletedForms: [],
        deletedFormsLoading: false,
        // 類組分布（整屆）
        groupDistribution: {
          groups: [],
          total_teams: 0
        },
        groupChart: null
      };
    },
    computed: {
      teacherStatsSummary() {
        if (this.teacherStatsLoading) return '載入中…';
        if (!this.teacherStats.length) return '尚無資料';
        return `${this.teacherStats.length} 位 · 成員 ${this.memberLimitMin}～${this.memberLimitMax} 人`;
      },
      allTeachersSelected() {
        if (!this.teacherStats.length) return false;
        return this.selectedTeacherIds.length === this.teacherStats.length;
      },
      listPending() {
        return this.list.filter(it => Number(it.tap_status) === 1).length;
      },
      listTeamed() {
        return this.list.filter(it => Number(it.tap_status) === 3).length;
      },
      totalRemaining() {
        return this.teacherStats.reduce((sum, t) => {
          const max = t.max_count != null ? Number(t.max_count) : 0;
          const cur = Number(t.current_count) || 0;
          return sum + Math.max(0, max - cur);
        }, 0);
      },
      teacherStatsWithStatus() {
        return this.teacherStats.map(t => {
          const cur = Number(t.current_count) || 0;
          const max = t.max_count != null ? Number(t.max_count) : 0;
          let statusKey = 'ok';
          let statusLabel = '可收';
          if (max <= 0) {
            statusLabel = '—';
          } else if (cur >= max) {
            statusKey = 'full';
            statusLabel = '已滿';
          } else if (cur >= Math.ceil(max * 2 / 3)) {
            statusKey = 'near';
            statusLabel = '快滿';
          } else if (cur === 0) {
            statusKey = 'none';
            statusLabel = '尚無';
          }
          return { ...t, statusKey, statusLabel };
        }).sort((a, b) => {
          const pctA = (a.max_count > 0) ? (a.current_count / a.max_count) : 0;
          const pctB = (b.max_count > 0) ? (b.current_count / b.max_count) : 0;
          return pctB - pctA;
        });
      },
      reminderLines() {
        const lines = [];
        const full = this.teacherStatsWithStatus.filter(t => t.statusKey === 'full');
        const near = this.teacherStatsWithStatus.filter(t => t.statusKey === 'near');
        const empty = this.teacherStatsWithStatus.filter(t => t.current_count === 0 && (t.max_count || 0) > 0);
        if (full.length) lines.push(`已滿額：${full.map(t => t.u_name).join('、')}`);
        if (near.length) lines.push(`快滿：${near.map(t => t.u_name).join('、')}`);
        if (empty.length) lines.push(`尚可收：${empty.map(t => t.u_name).join('、')}`);
        return lines;
      },
      // 類組圓餅圖：總筆數與百分比
      groupTotalTeams() {
        const dist = this.groupDistribution || {};
        const groups = dist.groups || [];
        return groups.reduce((sum, g) => sum + (Number(g.team_count) || 0), 0);
      },
      groupDistributionWithRatio() {
        const dist = this.groupDistribution || {};
        const groups = dist.groups || [];
        const total = this.groupTotalTeams || 0;
        if (!total) return groups;
        return groups.map(g => ({
          ...g,
          ratio: ((Number(g.team_count) || 0) / total) * 100
        }));
      }
    },
    mounted() {
      const el = document.getElementById('detailModal');
      if (el) {
        if (el.parentNode && el.parentNode !== document.body) {
          document.body.appendChild(el);
        }
        this.modal = new bootstrap.Modal(el, { backdrop: true, keyboard: true });
      }
      const limitEl = document.getElementById('teacherLimitModal');
      if (limitEl) {
        if (limitEl.parentNode && limitEl.parentNode !== document.body) {
          document.body.appendChild(limitEl);
        }
        this.modalTeacherLimit = new bootstrap.Modal(limitEl, { backdrop: true, keyboard: true });
      }
      this.loadForms();

      const onBeforeUnload = () => {
        try { if (this.modal) this.modal.hide(); } catch (e) {}
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
      };
      this.__onBeforeUnload = onBeforeUnload;

      const contentEl = document.querySelector('#content');
      if (contentEl) contentEl.addEventListener('pageBeforeUnload', onBeforeUnload);
    },
      beforeUnmount() {
      const contentEl = document.querySelector('#content');
      if (contentEl && this.__onBeforeUnload) contentEl.removeEventListener('pageBeforeUnload', this.__onBeforeUnload);

      const el = document.getElementById('detailModal');
      try { if (this.modal) this.modal.hide(); } catch (e) {}
      try { if (this.modalTeacherLimit) this.modalTeacherLimit.hide(); } catch (e) {}
      if (this.imageBlobUrl) {
        URL.revokeObjectURL(this.imageBlobUrl);
        this.imageBlobUrl = null;
      }
      document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
      document.body.classList.remove('modal-open');
    },
    methods: {
      statusText(s) {
        s = Number(s);
        if (s === 1) return '申請中';
        if (s === 2) return '退件';
        if (s === 3) return '已組隊';
        return '未知';
      },
      statusClass(s) {
        s = Number(s);
        if (s === 1) return 'pending';
        if (s === 2) return 'rejected';
        if (s === 3) return 'teamed';
        return '';
      },
      async loadData() {
        if (!this.selectedForm) return;
        this.loading = true;
        try {
          const url = `${API}?do=get_list&status=${encodeURIComponent(this.filterStatus)}&keyword=${encodeURIComponent(this.keyword)}&cohort_ID=${encodeURIComponent(this.selectedForm.taf_cohort_ID || 0)}`;
          const res = await fetch(url);
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || 'API Error');
          this.list = data.list || [];
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: '讀取失敗' });
        } finally {
          this.loading = false;
        }
      },
      getTafIdFromHash() {
        const hash = (window.location.hash || '').slice(1);
        const [path, query] = hash.split('?');
        if (!query) return null;
        const params = new URLSearchParams(query);
        const id = params.get('taf_ID');
        return id ? parseInt(id, 10) : null;
      },
      async loadForms() {
        this.formsLoading = true;
        try {
          const res = await fetch(`${API}?do=get_forms`);
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || 'API Error');
          this.forms = data.forms || [];
          const tafId = this.getTafIdFromHash();
          if (tafId && this.forms.length) {
            const f = this.forms.find(x => Number(x.taf_ID) === tafId);
            if (f) await this.selectForm(f);
          }
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: '讀取表單失敗' });
        } finally {
          this.formsLoading = false;
        }
      },
      async selectForm(form) {
        this.selectedForm = form;
        this.keyword = '';
        this.filterStatus = 'all';
        await this.loadData();
        this.loadTeacherStats();
        this.loadGroupDistribution();
        this.$nextTick(() => {
          this.renderGroupPie();
        });
      },
      goEditForm(form) {
        if (!form || !form.taf_ID) return;
        window.location.hash = `pages/team_apply_admin.php?taf_ID=${encodeURIComponent(form.taf_ID)}`;
      },
      goCreateForm() {
        window.location.hash = 'pages/team_apply_admin.php';
      },
      async showDeletedView() {
        this.showDeleted = true;
        this.selectedForm = null;
        await this.loadDeletedForms();
      },
      backFromDeletedView() {
        this.showDeleted = false;
        this.deletedForms = [];
      },
      async loadDeletedForms() {
        this.deletedFormsLoading = true;
        try {
          const res = await fetch(`${API}?do=get_deleted_forms`);
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || 'API Error');
          this.deletedForms = data.forms || [];
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: '讀取刪除紀錄失敗' });
        } finally {
          this.deletedFormsLoading = false;
        }
      },
      async restoreForm(form) {
        if (!form || !form.taf_ID) return;
        if (!window.Swal || !(await Swal.fire({
          title: '確定要復原？',
          text: `「${form.taf_title}」將恢復為啟用狀態`,
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: '復原',
          cancelButtonText: '取消',
          confirmButtonColor: '#198754'
        })).isConfirmed) return;
        try {
          const fd = new FormData();
          fd.append('do', 'restore_form');
          fd.append('taf_ID', form.taf_ID);
          const res = await fetch(API, { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || '復原失敗');
          Toast.fire({ icon: 'success', title: data.msg || '已復原' });
          await this.loadDeletedForms();
          await this.loadForms();
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: e.message || '復原失敗' });
        }
      },
      backToFormList() {
        this.selectedForm = null;
        this.list = [];
        this.groupDistribution = { groups: [], total_teams: 0 };
        if (this.groupChart) {
          this.groupChart.destroy();
          this.groupChart = null;
        }
      },
      getImageApiUrl(tap_ID, cacheBust = false) {
        if (!tap_ID) return '';
        const base = window.location.origin + (window.location.pathname || '/').replace(/\/[^/]*$/, '') + '/';
        let u = base + 'get_team_apply_image.php?tap_ID=' + tap_ID;
        if (cacheBust) u += '&_=' + Date.now();
        return u;
      },
      getDirectImageUrl(url, cacheBust = false) {
        if (!url || typeof url !== 'string') return '';
        if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) return url;
        const path = url.replace(/^\.?\//, '');
        const base = window.location.origin + (window.location.pathname || '/').replace(/\/[^/]*$/, '') + '/';
        return base + path + (cacheBust ? '?_=' + Date.now() : '');
      },
      resolveImageUrl(url, tap_ID, cacheBust = false) {
        if (this.imageBlobUrl) return this.imageBlobUrl;
        if (this.imageUseDirectPath) return this.getDirectImageUrl(url, cacheBust);
        return this.getImageApiUrl(tap_ID, cacheBust) || this.getDirectImageUrl(url, cacheBust);
      },
      async loadImageAsBlob() {
        if (!this.detail?.image_url) return;
        if (this.imageBlobUrl) {
          URL.revokeObjectURL(this.imageBlobUrl);
          this.imageBlobUrl = null;
        }
        this.imageLoading = true;
        this.imageLoadFailed = false;
        const cacheBust = this.imageRetryKey > 0;
        const directUrl = this.getDirectImageUrl(this.detail.image_url, cacheBust);
        const apiUrl = this.getImageApiUrl(this.detail.tap_ID, cacheBust);
        const urlsToTry = this.imageUseDirectPath ? [directUrl, apiUrl] : [directUrl, apiUrl];
        for (const imgUrl of urlsToTry) {
          if (!imgUrl) continue;
          try {
            const res = await fetch(imgUrl, { credentials: 'include' });
            if (!res.ok) continue;
            const blob = await res.blob();
            if (blob.type.startsWith('image/') || blob.type === 'application/pdf' || blob.size > 0) {
              this.imageBlobUrl = URL.createObjectURL(blob);
              this.imageLoadFailed = false;
              this.imageLoading = false;
              return;
            }
          } catch (e) { /* 嘗試下一個 URL */ }
        }
        this.imageLoadFailed = true;
        this.imageLoading = false;
      },
      onImageError(e) {
        this.imageLoadFailed = true;
        if (e && e.target) e.target.style.display = 'none';
      },
      retryImage() {
        this.imageLoadFailed = false;
        this.imageRetryKey++;
        if (this.imageRetryKey >= 2) this.imageUseDirectPath = true;
        this.loadImageAsBlob();
      },
      async openDetail(tap_ID) {
        if (this.imageBlobUrl) {
          URL.revokeObjectURL(this.imageBlobUrl);
          this.imageBlobUrl = null;
        }
        this.detail = {};
        this.imageLoadFailed = false;
        this.imageRetryKey = 0;
        this.imageUseDirectPath = false;
        this.detailLoading = true;
        if (this.modal) this.modal.show();
        try {
          const res = await fetch(`${API}?do=get_detail&tap_ID=${tap_ID}`);
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || 'API Error');
          this.detail = data.detail || {};
          this.$nextTick(() => this.loadImageAsBlob());
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: '讀取詳情失敗' });
          try { if (this.modal) this.modal.hide(); } catch (_) {}
        } finally {
          this.detailLoading = false;
          this.$nextTick(() => this.scrollToImageSecondPage());
        }
      },
      scrollToImageSecondPage() {
        const section = document.getElementById('detailImageSection');
        if (!section) return;
        const modalBody = document.querySelector('#detailModal .modal-body');
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const scrollToSecondHalf = () => {
          if (modalBody) {
            const halfH = section.offsetHeight / 2;
            modalBody.scrollTop = Math.max(0, modalBody.scrollTop + halfH - 80);
          }
        };
        setTimeout(scrollToSecondHalf, 400);
      },
      isPdfUrl(url) {
        if (!url || typeof url !== 'string') return false;
        const u = url.split('?')[0].toLowerCase();
        return u.endsWith('.pdf');
      },

      async saveRemark() {
        if (!this.detail || !this.detail.tap_ID) return;
        try {
          const fd = new FormData();
          fd.append('do', 'save_remark');
          fd.append('tap_ID', this.detail.tap_ID);
          fd.append('tap_remark', (this.detail.tap_remark || '').trim());
          const res = await fetch(API, { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || 'API Error');

          Toast.fire({ icon: 'success', title: '已儲存備註' });
          this.loadData();
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: '儲存失敗' });
        }
      },

      async approve() {
        await this.postStatus('approve', true);
      },

      async reject() {
        await this.postStatus('reject', true);
      },

      openTeacherLimitModal() {
        if (!this.selectedForm || !this.selectedForm.taf_cohort_ID) return;
        this.loadTeacherStats().then(() => {
          if (this.modalTeacherLimit) this.modalTeacherLimit.show();
        });
      },
      async loadTeacherStats() {
        if (!this.selectedForm || !this.selectedForm.taf_cohort_ID) return;
        this.teacherStatsLoading = true;
        this.editLimitTeacher = null;
        try {
          const cohort = this.selectedForm.taf_cohort_ID || 0;
          const tafId = this.selectedForm.taf_ID || 0;
          const res = await fetch(`${API}?do=get_teacher_stats&cohort_ID=${cohort}&taf_ID=${tafId}`);
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || '載入失敗');
          this.teacherStats = data.teachers || [];
          this.selectedTeacherIds = [];
          if (this.teacherStats.length) {
            this.memberLimitMin = this.teacherStats[0].member_min ?? 1;
            this.memberLimitMax = this.teacherStats[0].member_max ?? 4;
          }
        } catch (e) {
          console.error(e);
          this.teacherStats = [];
          Toast.fire({ icon: 'error', title: '載入指導老師統計失敗' });
        } finally {
          this.teacherStatsLoading = false;
        }
      },
      async loadGroupDistribution() {
        if (!this.selectedForm || !this.selectedForm.taf_cohort_ID) return;
        try {
          const cohort = this.selectedForm.taf_cohort_ID || 0;
          const res = await fetch(`${API}?do=get_group_distribution&cohort_ID=${cohort}`);
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || '載入失敗');
          this.groupDistribution = {
            groups: data.groups || [],
            total_teams: data.total_teams || 0
          };
          this.$nextTick(() => this.renderGroupPie());
        } catch (e) {
          console.error(e);
          this.groupDistribution = { groups: [], total_teams: 0 };
        }
      },
      renderGroupPie() {
        const canvas = document.getElementById('tarGroupPie');
        if (!canvas || !window.Chart) return;
        const ctx = canvas.getContext('2d');
        const groups = this.groupDistributionWithRatio || [];
        if (!groups.length) {
          if (this.groupChart) {
            this.groupChart.destroy();
            this.groupChart = null;
          }
          return;
        }
        const labels = groups.map(g => g.group_name);
        const dataVals = groups.map(g => Number(g.team_count) || 0);
        const bgColors = [
          '#4f46e5', '#22c55e', '#f97316', '#ec4899',
          '#0ea5e9', '#a855f7', '#eab308', '#64748b'
        ];
        const colors = dataVals.map((_, idx) => bgColors[idx % bgColors.length]);

        if (this.groupChart) {
          this.groupChart.data.labels = labels;
          this.groupChart.data.datasets[0].data = dataVals;
          this.groupChart.data.datasets[0].backgroundColor = colors;
          this.groupChart.update();
          return;
        }

        this.groupChart = new Chart(ctx, {
          type: 'doughnut',
          data: {
            labels,
            datasets: [{
              data: dataVals,
              backgroundColor: colors,
              borderWidth: 0
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: true,
                position: 'bottom',
                labels: {
                  boxWidth: 10,
                  font: { size: 11 }
                }
              },
              tooltip: {
                callbacks: {
                  label(context) {
                    const label = context.label || '';
                    const value = context.parsed || 0;
                    const total = dataVals.reduce((s, v) => s + v, 0) || 1;
                    const pct = Math.round((value / total) * 100);
                    return `${label}：${value} 組（${pct}%）`;
                  }
                }
              }
            },
            cutout: '62%'
          }
        });
      },
      startEditLimit(t) {
        this.editLimitTeacher = t.u_ID;
        this.editLimitValue = t.max_count != null ? t.max_count : 0;
      },
      cancelEditLimit() {
        this.editLimitTeacher = null;
      },
      toggleSelectAll() {
        if (this.allTeachersSelected) {
          this.selectedTeacherIds = [];
        } else {
          this.selectedTeacherIds = this.teacherStats.map(t => t.u_ID);
        }
      },
      async batchApplySmart() {
        const max = parseInt(this.batchLimitValue, 10);
        if (isNaN(max) || max < 0) {
          Toast.fire({ icon: 'warning', title: '請輸入有效的數字' });
          return;
        }
        const ids = this.selectedTeacherIds.length > 0 ? this.selectedTeacherIds : this.teacherStats.map(t => t.u_ID);
        await this.batchSaveLimits(ids, max);
      },
      async batchApplyToAll() {
        const max = parseInt(this.batchLimitValue, 10);
        if (isNaN(max) || max < 0) {
          Toast.fire({ icon: 'warning', title: '請輸入有效的數字' });
          return;
        }
        const ids = this.teacherStats.map(t => t.u_ID);
        await this.batchSaveLimits(ids, max);
      },
      async batchApplyToSelected() {
        const max = parseInt(this.batchLimitValue, 10);
        if (isNaN(max) || max < 0) {
          Toast.fire({ icon: 'warning', title: '請輸入有效的數字' });
          return;
        }
        if (this.selectedTeacherIds.length === 0) {
          Toast.fire({ icon: 'warning', title: '請先勾選要設定的老師' });
          return;
        }
        await this.batchSaveLimits(this.selectedTeacherIds, max);
      },
      async batchSaveLimits(teacherIds, maxCount) {
        try {
          const fd = new FormData();
          fd.append('do', 'batch_set_teacher_team_limit');
          fd.append('cohort_ID', this.selectedForm.taf_cohort_ID);
          fd.append('max_count', maxCount);
          teacherIds.forEach(id => fd.append('teacher_ids[]', id));
          const res = await fetch(API, { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || '儲存失敗');
          Toast.fire({ icon: 'success', title: data.msg || '已儲存' });
          this.selectedTeacherIds = [];
          await this.loadTeacherStats();
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: e.message || '儲存失敗' });
        }
      },
      async saveTeacherLimit(t) {
        const max = parseInt(this.editLimitValue, 10);
        if (isNaN(max) || max < 0) {
          Toast.fire({ icon: 'warning', title: '請輸入有效的數字' });
          return;
        }
        try {
          const fd = new FormData();
          fd.append('do', 'set_teacher_team_limit');
          fd.append('teacher_id', t.u_ID);
          fd.append('cohort_ID', this.selectedForm.taf_cohort_ID);
          fd.append('max_count', max);
          const res = await fetch(API, { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || '儲存失敗');
          Toast.fire({ icon: 'success', title: '已儲存' });
          this.editLimitTeacher = null;
          await this.loadTeacherStats();
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: e.message || '儲存失敗' });
        }
      },
      startEditMemberLimit() {
        this.editMemberMin = this.memberLimitMin;
        this.editMemberMax = this.memberLimitMax;
        this.editMemberLimit = true;
      },
      async saveMemberLimit() {
        const min = parseInt(this.editMemberMin, 10);
        const max = parseInt(this.editMemberMax, 10);
        if (isNaN(min) || isNaN(max) || min < 1 || max < 1) {
          Toast.fire({ icon: 'warning', title: '請輸入有效數字（至少 1）' });
          return;
        }
        if (min > max) {
          Toast.fire({ icon: 'warning', title: '最小人數不可大於最大人數' });
          return;
        }
        try {
          const fd = new FormData();
          fd.append('do', 'set_member_limit');
          fd.append('cohort_ID', this.selectedForm.taf_cohort_ID);
          fd.append('min_count', min);
          fd.append('max_count', max);
          const res = await fetch(API, { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || '儲存失敗');
          Toast.fire({ icon: 'success', title: '已儲存' });
          this.editMemberLimit = false;
          this.memberLimitMin = min;
          this.memberLimitMax = max;
          await this.loadTeacherStats();
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: e.message || '儲存失敗' });
        }
      },

      async postStatus(action, closeModalAfter = false) {
        if (!this.detail || !this.detail.tap_ID) return;
        try {
          const fd = new FormData();
          fd.append('do', action);
          fd.append('tap_ID', this.detail.tap_ID);
          const res = await fetch(API, { method: 'POST', body: fd });
          const data = await res.json();
          if (!data.ok) throw new Error(data.msg || 'API Error');

          Toast.fire({ icon: 'success', title: data.msg || '完成' });

          if (closeModalAfter) {
            try { if (this.modal) this.modal.hide(); } catch (_) {}
          }

          this.loadData();
        } catch (e) {
          console.error(e);
          Toast.fire({ icon: 'error', title: '操作失敗' });
        }
      }
    }
  });

  window.__teamApplyReviewApp = app;
  app.mount('#reviewApp');
})();
