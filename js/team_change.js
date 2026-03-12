/**
 * 組別異動紀錄頁面 - Vue 應用
 * js/team_change.js
 * 由 pages/team_change.php 呼叫 initTeamChange(config) 初始化
 */
(function () {
  'use strict';

  window.initTeamChange = function initTeamChange(config) {
    const el = document.getElementById('changelogApp');
    if (!el) return null;
    if (!config || typeof config !== 'object') {
      console.warn('initTeamChange: config 無效');
      return null;
    }
    if (typeof Vue === 'undefined' || !Vue.createApp) {
      console.warn('initTeamChange: Vue 未載入');
      return null;
    }

    const cfg = {
      isStudent: !!config.isStudent,
      isOffice: !!config.isOffice,
      isOfficeOrDirector: !!config.isOfficeOrDirector,
      team_ID: parseInt(config.team_ID, 10) || 0,
      teamName: (config.teamName != null && config.teamName !== '') ? String(config.teamName) : '',
      teacherCohort_ID: (config.teacherCohort_ID != null && config.teacherCohort_ID !== '') ? parseInt(config.teacherCohort_ID, 10) : null
    };

    const { createApp } = Vue;
    const app = createApp({
      data() {
        return {
          isStudent: cfg.isStudent,
          isOffice: cfg.isOffice,
          isOfficeOrDirector: cfg.isOfficeOrDirector,
          team_ID: cfg.team_ID,
          teamName: cfg.teamName,
          changes: [],
          stats: null,
          cohorts: [],
          teams: [],
          filters: { cohort_ID: (cfg.teacherCohort_ID > 0 ? String(cfg.teacherCohort_ID) : ''), change_type: '', team_ID: '', team_search: '' },
          loading: true,
          detail: null,
          page: 1,
          pageSize: 10,
          debounceTimer: null,
          applyType: '',
          applyFormTitleCustom: '',
          applyFormTcfId: null,
          availableChangeForms: [],
          applyForm: { tc_team_name_new: '', tc_teacher_new: '', tc_member: '', reason: '', attachmentFile: null, attachmentPreview: '' },
          formData: { team_project_name: '', teacher: null, members: [], teachers: [] },
          availableStudents: [],
          applySubmitting: false,
          showDownloadButtons: false,
          applyModal: null,
          editTarget: null,
          editForm: { status: '1', tc_u_reason: '' },
          editSubmitting: false,
          editModal: null,
          reapplyTarget: null,
          reapplyForm: { tc_team_name_new: '', tc_teacher_new: '', tc_member: '', reason: '' },
          reapplySubmitting: false,
          reapplyModal: null,
          formCohorts: [],
          createForm: { tcf_cohort_ID: '', tcf_change_type: '', tcf_name: '', tcf_open_d: '', tcf_close_d: '' },
          createFormSubmitting: false,
          createFormModal: null,
          officeChangeForms: [],
          editFormTarget: null,
          editFormName: '',
          editFormOpenD: '',
          editFormCloseD: '',
          editFormSubmitting: false,
          editFormModal: null
        };
      },
      computed: {
        applyFormTitle() {
          if (this.applyFormTitleCustom) return this.applyFormTitleCustom;
          const t = { 'TEAM_RENAME': '專題題目變更申請單', 'TEACHER_CHANGE': '專題指導老師變更申請單', 'MEMBER_ADD': '專題組員異動(新增)申請單', 'MEMBER_REMOVE': '專題組員異動(退組)申請單', 'MEMBER_CHANGE': '專題組員異動申請單' };
          return t[this.applyType] || '變更申請';
        },
        computedStats() {
          const s = { TEAM_RENAME: 0, TEACHER_CHANGE: 0, MEMBER_ADD: 0, MEMBER_REMOVE: 0, MEMBER_CHANGE: 0, PENDING: 0 };
          this.changes.forEach(c => {
            if (s[c.change_type] !== undefined) s[c.change_type]++;
            if (Number(c.tc_status) === 1) s.PENDING++;
          });
          return s;
        },
        paginatedChanges() {
          const start = (this.page - 1) * this.pageSize;
          return this.changes.slice(start, start + this.pageSize);
        },
        totalPages() {
          return Math.max(1, Math.ceil(this.changes.length / this.pageSize));
        },
        pageNumbers() {
          const start = Math.max(1, this.page - 2);
          const end = Math.min(this.totalPages, start + 4);
          const arr = [];
          for (let p = start; p <= end; p++) arr.push(p);
          return arr;
        }
      },
      async mounted() {
        const createModal = (el) => {
          if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
          try {
            if (el.parentNode && el.parentNode !== document.body) document.body.appendChild(el);
            return new bootstrap.Modal(el);
          } catch (e) { console.warn('Bootstrap.Modal init failed', e); return null; }
        };
        this.applyModal = createModal(document.getElementById('applyModal'));
        this.editModal = createModal(document.getElementById('editChangelogModal'));
        this.reapplyModal = createModal(document.getElementById('reapplyModal'));
        this.createFormModal = createModal(document.getElementById('createFormModal'));
        this.editFormModal = createModal(document.getElementById('editFormModal'));
        if (this.isStudent) {
          if (this.team_ID > 0) {
            await this.loadStudentChanges();
            await this.loadAvailableChangeForms();
          } else this.loading = false;
        } else {
          await this.loadFilterOptions();
          await this.loadData();
          if (this.isOffice) await this.loadFormCohorts();
          if (this.isOfficeOrDirector) await this.loadOfficeChangeForms();
        }
      },
      methods: {
        apiPath() { return location.pathname.includes('/pages/') ? '../api.php' : 'api.php'; },
        getAttachmentUrl(path) { return path ? ((location.pathname.includes('/pages/') ? '../' : '') + path) : ''; },
        async loadAvailableChangeForms() {
          try {
            const res = await fetch(this.apiPath() + '?do=get_available_change_forms&team_ID=' + this.team_ID);
            const data = await res.json();
            this.availableChangeForms = (data.ok && data.forms) ? data.forms : [];
          } catch (e) { this.availableChangeForms = []; }
        },
        async loadStudentChanges() {
          this.loading = true;
          try {
            const res = await fetch(this.apiPath() + '?do=get_my_team_changelog&team_ID=' + this.team_ID);
            const data = await res.json();
            if (data.ok && data.changes) { this.changes = data.changes; this.page = 1; }
          } catch (e) { console.error(e); }
          finally { this.loading = false; }
        },
        async loadFormCohorts() {
          try {
            const res = await fetch(this.apiPath() + '?do=get_active_cohorts_for_form');
            const data = await res.json();
            if (data.ok && data.cohorts) this.formCohorts = data.cohorts;
          } catch (e) { this.formCohorts = []; }
        },
        async loadOfficeChangeForms() {
          try {
            const q = new URLSearchParams({ do: 'get_office_change_forms' });
            if (this.filters.cohort_ID) q.set('cohort_ID', this.filters.cohort_ID);
            const res = await fetch(this.apiPath() + '?' + q.toString());
            const data = await res.json();
            this.officeChangeForms = (data.ok && data.forms) ? data.forms : [];
          } catch (e) { this.officeChangeForms = []; }
        },
        formatFormPeriod(openD, closeD) {
          const o = openD ? String(openD).slice(0, 16).replace('T', ' ') : '—';
          const c = closeD ? String(closeD).slice(0, 16).replace('T', ' ') : '無截止';
          return o === '—' && c === '無截止' ? '立即開放' : (o + ' ～ ' + c);
        },
        toDatetimeLocal(val) {
          if (!val) return '';
          const s = String(val).trim().replace(' ', 'T').slice(0, 16);
          return s;
        },
        openEditFormModal(f) {
          this.editFormTarget = f;
          this.editFormName = f.tcf_name || '';
          this.editFormOpenD = this.toDatetimeLocal(f.tcf_open_d);
          this.editFormCloseD = this.toDatetimeLocal(f.tcf_close_d);
          this.$nextTick(() => {
            if (this.editFormModal) this.editFormModal.show();
            else console.warn('editFormModal 未初始化，請確認 Bootstrap 已載入');
          });
        },
        async submitEditForm() {
          if (!this.editFormTarget) return;
          this.editFormSubmitting = true;
          try {
            const fd = new FormData();
            fd.append('tcf_ID', this.editFormTarget.tcf_ID);
            fd.append('tcf_name', this.editFormName || '');
            fd.append('tcf_open_d', this.editFormOpenD || '');
            fd.append('tcf_close_d', this.editFormCloseD || '');
            const res = await fetch(this.apiPath() + '?do=update_team_change_form', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
              if (this.editFormModal) this.editFormModal.hide();
              if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: data.message || '已儲存' });
              else alert(data.message || '已儲存');
              await this.loadOfficeChangeForms();
            } else {
              alert(data.msg || '儲存失敗');
            }
          } catch (e) { alert('儲存失敗'); }
          finally { this.editFormSubmitting = false; }
        },
        async openCreateFormModal() {
          this.createForm = { tcf_cohort_ID: '', tcf_change_type: '', tcf_name: '', tcf_open_d: '', tcf_close_d: '' };
          await this.loadFormCohorts();
          this.$nextTick(() => { if (this.createFormModal) this.createFormModal.show(); });
        },
        async submitCreateForm() {
          this.createFormSubmitting = true;
          try {
            const fd = new FormData();
            fd.append('tcf_cohort_ID', this.createForm.tcf_cohort_ID);
            fd.append('tcf_change_type', this.createForm.tcf_change_type);
            fd.append('tcf_name', this.createForm.tcf_name || '');
            fd.append('tcf_open_d', this.createForm.tcf_open_d || '');
            fd.append('tcf_close_d', this.createForm.tcf_close_d || '');
            const res = await fetch(this.apiPath() + '?do=create_team_change_form', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
              if (this.createFormModal) this.createFormModal.hide();
              if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: data.message || '申請單已建立' });
              else alert(data.message || '申請單已建立');
            } else {
              alert(data.msg || '建立失敗');
            }
          } catch (e) { alert('建立失敗'); }
          finally { this.createFormSubmitting = false; }
        },
        async loadFilterOptions() {
          try {
            const q = new URLSearchParams({ do: 'get_changelog_filter_options' });
            const cohortVal = this.filters.cohort_ID || (cfg.teacherCohort_ID > 0 ? String(cfg.teacherCohort_ID) : '');
            if (cohortVal) q.set('cohort_ID', cohortVal);
            const res = await fetch(this.apiPath() + '?' + q);
            const data = await res.json();
            if (data.ok) {
              this.cohorts = data.cohorts || [];
              this.teams = data.teams || [];
            }
          } catch (e) { console.error(e); }
        },
        async onCohortChange() {
          this.filters.team_ID = '';
          await this.loadFilterOptions();
          await this.loadData();
          if (this.isOfficeOrDirector) await this.loadOfficeChangeForms();
        },
        async loadData() {
          this.loading = true;
          try {
            const q = new URLSearchParams({ do: 'get_changelog_list' });
            if (this.filters.cohort_ID) q.set('cohort_ID', this.filters.cohort_ID);
            if (this.filters.change_type) q.set('change_type', this.filters.change_type);
            if (this.filters.team_ID) q.set('team_ID', this.filters.team_ID);
            if (this.filters.team_search) q.set('team_search', this.filters.team_search);
            const res = await fetch(this.apiPath() + '?' + q);
            const data = await res.json();
            if (data.ok && data.changes) { this.changes = data.changes; this.page = 1; }

            const q2 = new URLSearchParams({ do: 'get_changelog_stats' });
            if (this.filters.cohort_ID) q2.set('cohort_ID', this.filters.cohort_ID);
            const res2 = await fetch(this.apiPath() + '?' + q2);
            const d2 = await res2.json();
            if (d2.ok && d2.stats) this.stats = d2.stats;
          } catch (e) { console.error(e); }
          finally { this.loading = false; }
        },
        debounceLoad() { clearTimeout(this.debounceTimer); this.debounceTimer = setTimeout(() => this.loadData(), 400); },
        typeLabel(type) {
          const map = { 'TEAM_RENAME': '組名變更', 'TEACHER_CHANGE': '指導老師變更', 'MEMBER_ADD': '成員新增', 'MEMBER_REMOVE': '成員移除', 'MEMBER_CHANGE': '成員異動' };
          return map[type] || type;
        },
        statusLabel(s) { const map = { 0: '退件', 1: '申請', 2: '等待老師簽名', 3: '通過', 4: '暫存' }; return map[String(s)] || '—'; },
        typeDotColor(t) {
          if (t === 'TEAM_RENAME') return '#1d4ed8';
          if (t === 'TEACHER_CHANGE') return '#0f766e';
          if (t === 'MEMBER_ADD' || t === 'MEMBER_REMOVE' || t === 'MEMBER_CHANGE') return '#7c3aed';
          return '#111';
        },
        buildSummary(c) {
          if (c.change_type === 'TEAM_RENAME') return (c.tc_team_name_old || '—') + ' → ' + (c.tc_team_name_new || '—');
          if (c.change_type === 'TEACHER_CHANGE') return (c.tc_teacher_old || '—') + ' → ' + (c.tc_teacher_new || '—');
          if (c.change_type === 'MEMBER_ADD' || c.change_type === 'MEMBER_CHANGE') return '新增：' + (c.tc_member_display || c.tc_member || '—');
          if (c.change_type === 'MEMBER_REMOVE') return '移除：' + (c.tc_member_display || c.tc_member || '—');
          return '—';
        },
        openDetail(c) { this.detail = c; },
        closeDetail() { this.detail = null; },
        async openReapplyModal(c) {
          if (!c || !this.team_ID) return;
          this.reapplyTarget = c;
          this.reapplyForm = {
            tc_team_name_new: (c.tc_team_name_new || '').trim(),
            tc_teacher_new: '',
            tc_member: (c.tc_member || '').trim(),
            reason: (c.tc_reason || '').trim()
          };
          try {
            const res = await fetch(this.apiPath() + '?do=get_team_change_form_data&team_ID=' + this.team_ID);
            const data = await res.json();
            if (data.ok) {
              this.formData = { team_project_name: data.team_project_name || '', teacher: data.teacher, members: data.members || [], teachers: data.teachers || [] };
              this.reapplyForm.tc_teacher_new = this.getTeacherIdFromRecord(c);
              if (c.change_type === 'MEMBER_ADD' || c.change_type === 'MEMBER_CHANGE') {
                const r2 = await fetch(this.apiPath() + '?do=get_available_students_for_add&team_ID=' + this.team_ID);
                const d2 = await r2.json();
                this.availableStudents = (d2.ok && d2.students) ? d2.students : [];
              }
            }
          } catch (e) { console.error(e); }
          this.$nextTick(() => {
            if (this.reapplyModal) this.reapplyModal.show();
          });
        },
        getTeacherIdFromRecord(c) {
          if (c.change_type !== 'TEACHER_CHANGE' || !c.tc_teacher_new) return '';
          const teachers = this.formData.teachers || [];
          const t = teachers.find(x => x.u_ID === c.tc_teacher_new || x.u_name === c.tc_teacher_new);
          return t ? t.u_ID : (c.tc_teacher_new || '');
        },
        isReapplyDataUnchanged() {
          const o = this.reapplyTarget;
          if (!o) return false;
          const f = this.reapplyForm;
          if (o.change_type === 'TEAM_RENAME') {
            return (f.tc_team_name_new || '').trim() === (o.tc_team_name_new || '').trim() &&
                   (f.reason || '').trim() === (o.tc_reason || '').trim();
          }
          if (o.change_type === 'TEACHER_CHANGE') {
            const origId = this.getTeacherIdFromRecord(o);
            return (f.tc_teacher_new || '') === (origId || '') &&
                   (f.reason || '').trim() === (o.tc_reason || '').trim();
          }
          if (['MEMBER_ADD', 'MEMBER_REMOVE', 'MEMBER_CHANGE'].includes(o.change_type)) {
            return (f.tc_member || '').trim() === (o.tc_member || '').trim() &&
                   (f.reason || '').trim() === (o.tc_reason || '').trim();
          }
          return false;
        },
        async submitReapply() {
          if (!this.reapplyTarget) return;
          const unchanged = this.isReapplyDataUnchanged();
          if (unchanged) {
            const result = typeof Swal !== 'undefined'
              ? await Swal.fire({ title: '資料未更動', text: '是否仍要提交申請？', icon: 'question', showCancelButton: true, confirmButtonText: '是，提交', cancelButtonText: '取消' })
              : { isConfirmed: confirm('資料未更動 是否提交申請？') };
            if (!result.isConfirmed) return;
          }
          this.reapplySubmitting = true;
          try {
            const fd = new FormData();
            fd.append('tc_ID', this.reapplyTarget.tc_ID);
            if (this.reapplyTarget.change_type === 'TEAM_RENAME') fd.append('tc_team_name_new', this.reapplyForm.tc_team_name_new);
            if (this.reapplyTarget.change_type === 'TEACHER_CHANGE') fd.append('tc_teacher_new', this.reapplyForm.tc_teacher_new);
            if (['MEMBER_ADD', 'MEMBER_REMOVE', 'MEMBER_CHANGE'].includes(this.reapplyTarget.change_type)) fd.append('tc_member', this.reapplyForm.tc_member);
            fd.append('reason', this.reapplyForm.reason || '');
            const res = await fetch(this.apiPath() + '?do=reapply_changelog', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
              this.reapplyModal.hide();
              await this.loadStudentChanges();
              if (this.detail && this.detail.tc_ID === this.reapplyTarget.tc_ID) {
                const updated = this.changes.find(x => x.tc_ID === this.reapplyTarget.tc_ID);
                this.detail = updated ? { ...updated } : null;
              }
              if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: data.message || '已重新送出申請' });
              else alert(data.message || '已重新送出申請');
            } else {
              alert(data.msg || '送出失敗');
            }
          } catch (e) { alert('送出失敗'); }
          finally { this.reapplySubmitting = false; }
        },
        openEditModal(c) {
          this.editTarget = c;
          this.editForm = {
            status: String(c.tc_status ?? 1),
            tc_u_reason: (c.tc_u_reason || '').trim()
          };
          this.$nextTick(() => {
            if (this.editModal) this.editModal.show();
            else console.warn('editModal 未初始化，請確認 Bootstrap 已載入');
          });
        },
        async saveEdit() {
          if (!this.editTarget) return;
          const oldStatus = parseInt(this.editTarget.tc_status, 10);
          const newStatus = parseInt(this.editForm.status, 10);
          if (oldStatus === 3 && newStatus !== 3) {
            const targetLabel = this.statusLabel(newStatus);
            if (typeof Swal !== 'undefined') {
              const result = await Swal.fire({
                title: '此申請已被通過',
                html: `確定要改為<b>${targetLabel}</b>嗎？`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '確定',
                cancelButtonText: '取消',
                reverseButtons: true
              });
              if (!result.isConfirmed) return;
            } else if (!confirm(`此申請已被通過，確定要改為${targetLabel}嗎？`)) return;
          }
          this.editSubmitting = true;
          try {
            const fd = new FormData();
            fd.append('tc_ID', this.editTarget.tc_ID);
            fd.append('status', this.editForm.status);
            fd.append('tc_u_reason', this.editForm.tc_u_reason || '');
            const res = await fetch(this.apiPath() + '?do=edit_changelog', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
              this.editModal.hide();
              await this.loadData();
              const tid = this.editTarget.tc_ID;
              if (this.detail && this.detail.tc_ID === tid) {
                const updated = this.changes.find(x => x.tc_ID === tid);
                this.detail = updated ? { ...updated } : { ...this.detail, tc_status: parseInt(this.editForm.status, 10), tc_u_reason: this.editForm.tc_u_reason };
              }
              if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: data.message || '已更新' });
              else alert(data.message || '已更新');
            } else {
              alert(data.msg || '儲存失敗');
            }
          } catch (e) { alert('儲存失敗'); }
          finally { this.editSubmitting = false; }
        },
        async openApplyModal(type, customTitle, tcfId) {
          this.applyType = type;
          this.applyFormTitleCustom = customTitle || '';
          this.applyFormTcfId = (tcfId != null && tcfId !== '') ? Number(tcfId) : null;
          this.applyForm = { tc_team_name_new: '', tc_teacher_new: '', tc_member: '', reason: '', attachmentFile: null, attachmentPreview: '' };
          this.showDownloadButtons = false;
          const dd = document.getElementById('applyChangeDropdownBtn');
          if (dd) {
            const instance = bootstrap.Dropdown.getInstance(dd);
            if (instance) instance.hide();
          }
          try {
            const res = await fetch(this.apiPath() + '?do=get_team_change_form_data&team_ID=' + this.team_ID);
            const data = await res.json();
            if (data.ok) {
              this.formData = { team_project_name: data.team_project_name || '', teacher: data.teacher, members: data.members || [], teachers: data.teachers || [] };
              if (type === 'MEMBER_ADD') {
                const r2 = await fetch(this.apiPath() + '?do=get_available_students_for_add&team_ID=' + this.team_ID);
                const d2 = await r2.json();
                this.availableStudents = (d2.ok && d2.students) ? d2.students : [];
              }
            }
          } catch (e) { console.error(e); }
          this.$nextTick(() => {
            setTimeout(() => {
              if (this.applyModal) this.applyModal.show();
            }, 50);
          });
        },
        async saveAndShowDownload() {
          // 先呼叫暫存 API，寫入 DB（tc_status=4），列表會出現該筆
          this.applySubmitting = true;
          try {
            const fd = new FormData();
            fd.append('team_ID', this.team_ID);
            fd.append('change_type', this.applyType);
            if (this.applyFormTcfId != null && this.applyFormTcfId !== '') fd.append('tcf_ID', this.applyFormTcfId);
            if (this.applyType === 'TEAM_RENAME') fd.append('tc_team_name_new', this.applyForm.tc_team_name_new);
            if (this.applyType === 'TEACHER_CHANGE') fd.append('tc_teacher_new', this.applyForm.tc_teacher_new);
            if (this.applyType === 'MEMBER_ADD' || this.applyType === 'MEMBER_REMOVE' || this.applyType === 'MEMBER_CHANGE') fd.append('tc_member', this.applyForm.tc_member);
            fd.append('reason', this.applyForm.reason || '');
            if (this.applyForm.attachmentFile) fd.append('attachment', this.applyForm.attachmentFile);
            const res = await fetch(this.apiPath() + '?do=save_change_draft', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.ok) {
              alert(data.msg || '暫存失敗');
              return;
            }
            this.showDownloadButtons = true;
            await this.loadStudentChanges();
            if (typeof Swal !== 'undefined') {
              Swal.fire({ icon: 'success', title: '已暫存', text: '資料已出現在列表中；可下載 PDF/Word 或列印。', timer: 2500, showConfirmButton: false });
            } else {
              alert('已暫存，資料已出現在列表中。');
            }
            if (typeof html2pdf !== 'undefined') this.downloadFormPDF();
          } catch (e) {
            alert('暫存失敗，請稍後再試');
          } finally {
            this.applySubmitting = false;
          }
        },
        getFormExportHtml() {
          // 確保使用最新表單資料（Vue 可能尚未 flush）
          const form = { ...this.applyForm };
          const formData = { ...this.formData };
          const title = this.applyFormTitle;
          let rows = `<tr><td colspan="2" style="text-align:center;font-size:18px;font-weight:bold;padding:16px;">${title}</td></tr>`;
          rows += `<tr><td style="width:140px;padding:8px;font-weight:bold;">${this.applyType === 'TEAM_RENAME' ? '原專題題目' : '專題題目'}</td><td style="padding:8px;">${(formData.team_project_name || '—')}</td></tr>`;
          if (this.applyType === 'TEAM_RENAME') {
            rows += `<tr><td style="padding:8px;font-weight:bold;">新專題題目</td><td style="padding:8px;">${(form.tc_team_name_new || '—')}</td></tr>`;
          } else if (this.applyType === 'TEACHER_CHANGE') {
            const newTeacher = (formData.teachers || []).find(t => t.u_ID === form.tc_teacher_new);
            rows += `<tr><td style="padding:8px;font-weight:bold;">原指導老師</td><td style="padding:8px;">${(formData.teacher && formData.teacher.u_name) || '—'}</td></tr>`;
            rows += `<tr><td style="padding:8px;font-weight:bold;">新指導老師</td><td style="padding:8px;">${(newTeacher && newTeacher.u_name) || form.tc_teacher_new || '—'}</td></tr>`;
          } else if (this.applyType === 'MEMBER_ADD' || this.applyType === 'MEMBER_CHANGE') {
            const newMember = (this.availableStudents || []).find(s => s.u_ID === form.tc_member);
            rows += `<tr><td style="padding:8px;font-weight:bold;">原組員</td><td style="padding:8px;">${(formData.members || []).map(m => m.u_name).join('、') || '—'}</td></tr>`;
            rows += `<tr><td style="padding:8px;font-weight:bold;">新增組員</td><td style="padding:8px;">${(newMember && newMember.u_name) || form.tc_member || '—'}</td></tr>`;
          } else if (this.applyType === 'MEMBER_REMOVE') {
            const leaveMember = (formData.members || []).find(m => m.u_ID === form.tc_member);
            rows += `<tr><td style="padding:8px;font-weight:bold;">原組員</td><td style="padding:8px;">${(formData.members || []).map(m => m.u_name).join('、') || '—'}</td></tr>`;
            rows += `<tr><td style="padding:8px;font-weight:bold;">退出組員</td><td style="padding:8px;">${(leaveMember && leaveMember.u_name) || form.tc_member || '—'}</td></tr>`;
          }
          const reasonLabel = (this.applyType === 'MEMBER_ADD' || this.applyType === 'MEMBER_CHANGE') ? '新增原因' : this.applyType === 'MEMBER_REMOVE' ? '退出原因' : '變更原因';
          rows += `<tr><td style="padding:8px;font-weight:bold;">${reasonLabel}</td><td style="padding:8px;">${(form.reason || '—').replace(/\n/g, '<br>')}</td></tr>`;
          if (form.attachmentPreview) {
            rows += `<tr><td style="padding:8px;font-weight:bold;">附件圖片</td><td style="padding:8px;"><img src="${form.attachmentPreview}" alt="附件" style="max-width:200px;max-height:150px;" /></td></tr>`;
          }
          rows += `<tr><td style="padding:8px;font-weight:bold;">申請日期</td><td style="padding:8px;">${new Date().toLocaleDateString('zh-TW', { year: 'numeric', month: '2-digit', day: '2-digit' })}</td></tr>`;
          const signatureBlock = `<div class="tc-print-signature" style="margin-top:24px;padding-top:16px;border-top:1px solid #333;font-size:16px;">
                <p style="margin:8px 0;">指導老師簽名：________________　　日期：____年____月____日</p>
              </div>`;
          return `<div style="font-family:Microsoft JhengHei,sans-serif;padding:24px;max-width:600px;">
            <table border="1" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:16px;">
            ${rows}
            </table>
            ${signatureBlock}
          </div>`;
        },
        downloadFormPDF() {
          if (typeof html2pdf === 'undefined') { alert('PDF 功能載入中，請稍後再試'); return; }
          this.$nextTick(() => {
            const html = this.getFormExportHtml();
            const typeNames = { 'TEAM_RENAME': '專題題目變更', 'TEACHER_CHANGE': '指導老師變更', 'MEMBER_ADD': '組員新增', 'MEMBER_REMOVE': '組員退組', 'MEMBER_CHANGE': '組員異動' };
            const fname = `${typeNames[this.applyType] || '變更'}_${(this.formData.team_project_name || '申請單').substring(0, 20)}_${new Date().toISOString().slice(0, 10)}.pdf`;
            html2pdf().set({
              margin: 10,
              filename: fname,
              image: { type: 'jpeg', quality: 0.98 },
              html2canvas: { scale: 2 },
              jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            }).from(html).save();
          });
        },
        downloadFormWord() {
          this.$nextTick(() => {
            const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta charset="utf-8"><title>Document</title></head><body>' + this.getFormExportHtml() + '</body></html>';
            const blob = new Blob(['\ufeff' + html], { type: 'application/msword' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            const typeNames = { 'TEAM_RENAME': '專題題目變更', 'TEACHER_CHANGE': '指導老師變更', 'MEMBER_ADD': '組員新增', 'MEMBER_REMOVE': '組員退組', 'MEMBER_CHANGE': '組員異動' };
            a.download = `${typeNames[this.applyType] || '變更'}_${(this.formData.team_project_name || '申請單').substring(0, 20)}_${new Date().toISOString().slice(0, 10)}.doc`;
            a.click();
            URL.revokeObjectURL(a.href);
          });
        },
        printForm() {
          this.$nextTick(() => {
            const html = this.getFormExportHtml();
            const printWin = window.open('', '_blank');
            if (!printWin) { alert('無法開啟列印視窗，請允許彈出視窗後再試'); return; }
            printWin.document.write(
              '<!DOCTYPE html><html><head><meta charset="utf-8"><title>列印 - ' + this.applyFormTitle + '</title>' +
              '<style type="text/css">body{ margin:0; padding:16px; font-family:Microsoft JhengHei,sans-serif; } @media print{ body{ padding:0; } }</style></head><body>' +
              html +
              '</body></html>'
            );
            printWin.document.close();
            printWin.focus();
            setTimeout(function() { printWin.print(); printWin.close(); }, 300);
          });
        },
        onApplyAttachmentChange(e) {
          const file = e.target.files && e.target.files[0];
          if (!file) return;
          if (!file.type.startsWith('image/')) { alert('請選擇圖片檔（jpg、png、gif 等）'); return; }
          if (file.size > 5 * 1024 * 1024) { alert('圖片大小請勿超過 5MB'); return; }
          const reader = new FileReader();
          reader.onload = () => { this.applyForm.attachmentPreview = reader.result; this.applyForm.attachmentFile = file; };
          reader.readAsDataURL(file);
        },
        clearApplyAttachment() {
          this.applyForm.attachmentFile = null;
          this.applyForm.attachmentPreview = '';
          if (this.$refs.applyAttachmentInput) this.$refs.applyAttachmentInput.value = '';
        },
        async submitApply() {
          this.applySubmitting = true;
          try {
            const fd = new FormData();
            fd.append('team_ID', this.team_ID);
            fd.append('change_type', this.applyType);
            if (this.applyType === 'TEAM_RENAME') fd.append('tc_team_name_new', this.applyForm.tc_team_name_new);
            if (this.applyType === 'TEACHER_CHANGE') fd.append('tc_teacher_new', this.applyForm.tc_teacher_new);
            if (this.applyType === 'MEMBER_ADD' || this.applyType === 'MEMBER_REMOVE' || this.applyType === 'MEMBER_CHANGE') fd.append('tc_member', this.applyForm.tc_member);
            fd.append('reason', this.applyForm.reason || '');
            if (this.applyForm.attachmentFile) fd.append('attachment', this.applyForm.attachmentFile);
            const res = await fetch(this.apiPath() + '?do=submit_change_application', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
              this.applyModal.hide();
              await this.loadStudentChanges();
              if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: '申請已送出', text: data.message });
              else alert(data.message);
            } else {
              alert(data.msg || '送出失敗');
            }
          } catch (e) { alert('送出失敗'); }
          finally { this.applySubmitting = false; }
        },
        async updateStatus(tc_ID, status) {
          const isPass = status === 3;
          let tc_u_reason = '';
          if (typeof Swal !== 'undefined') {
            const result = await Swal.fire({
              title: isPass ? '確定通過？' : '確定退件？',
              html: '<label class="form-label text-start d-block mb-2">審核人備註（選填）</label>' +
                '<textarea id="swal-tc-u-reason" class="form-control" rows="3" placeholder="' + (isPass ? '通過時可填寫備註說明' : '退件時建議填寫不通過原因，方便申請人了解') + '" maxlength="300"></textarea>' +
                '<small class="text-muted d-block mt-1 text-start">最多 300 字</small>',
              showCancelButton: true,
              confirmButtonText: isPass ? '通過' : '退件',
              cancelButtonText: '取消',
              confirmButtonColor: isPass ? '#198754' : '#dc3545',
              preConfirm: () => {
                const el = document.getElementById('swal-tc-u-reason');
                return el ? el.value.trim() : '';
              }
            });
            if (!result.isConfirmed) return;
            tc_u_reason = result.value || '';
          } else {
            tc_u_reason = prompt(isPass ? '審核人備註（選填）：' : '請填寫不通過原因（選填）：', '') || '';
            if (!confirm(isPass ? '確定通過？' : '確定退件？')) return;
          }
          try {
            const fd = new FormData();
            fd.append('tc_ID', tc_ID);
            fd.append('status', status);
            fd.append('tc_u_reason', tc_u_reason);
            const res = await fetch(this.apiPath() + '?do=update_changelog_status', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) { await this.loadData(); this.detail = null; }
            else alert(data.msg || '操作失敗');
          } catch (e) { alert('操作失敗'); }
        }
      }
    });

    app.mount('#changelogApp');
    return app;
  };
})();
