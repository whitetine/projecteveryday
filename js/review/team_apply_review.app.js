  //review/team_apply_review.app.js
  (function () {
    const { createApp } = Vue;

    function badgeClass(status) {
      const s = Number(status);
      if (s === 1) return 'bg-warning text-dark';
      if (s === 2) return 'bg-danger';
      if (s === 3) return 'bg-success';
      return 'bg-secondary';
    }

    function statusText(status) {
      const s = Number(status);
      if (s === 1) return '待審核';
      if (s === 2) return '已退件';
      if (s === 3) return '已通過';
      return '未知';
    }

    function resolveUrl(p) {
      // 若你 tap_url 存的是 'uploads/...' 這裡幫你補 base
      if (!p) return '#';
      if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('/')) return p;
      return window.APP_BASE + p.replace(/^\.?\//, '');
    }

    window.createTeamApplyReviewApp = function () {
      const el = document.querySelector('#reviewApp');
      if (!el) return;

      const app = createApp({
        data() {
          return {
            loading: true,
            errorMsg: '',
            cohorts: [],
            selectedCohort: 0,
            filterStatus: 'all',
            keyword: '',
            list: [],

            // remark modal
            remarkDraft: '',
            remarkTarget: null,

            // teacher modal
            teacherModal: {
              id: '',
              name: '',
              loading: false,
              error: '',
              teams: []
            }
          };
        },
        computed: {
          filteredList() {
            const kw = (this.keyword || '').toLowerCase();
            const st = this.filterStatus;

            return (this.list || []).filter(a => {
              if (st !== 'all' && String(a.tap_status) !== String(st)) return false;

              if (!kw) return true;
              const pool = [
                a.project_name, a.teacher_name, a.co_teacher_name,
                a.group_name, a.submitter_name, a.tap_u_ID,
                ...(a.members || []).map(m => `${m.u_name} ${m.u_ID}`)
              ].filter(Boolean).join(' ').toLowerCase();

              return pool.includes(kw);
            });
          }
        },
        methods: {
          badgeClass,
          statusText,
          resolveUrl,

          async init() {
            this.loading = true;
            this.errorMsg = '';
            try {
              await this.loadCohorts();
              await this.loadApplications();
            } catch (e) {
              this.errorMsg = e.message || String(e);
            } finally {
              this.loading = false;
            }
          },

          async loadCohorts() {
            const r = await apiFetch('get_active_cohorts');
            this.cohorts = r.cohorts || [];
          },

          async loadApplications() {
            this.loading = true;
            this.errorMsg = '';
            try {
              const r = await apiFetch('get_pending_applications', {
                query: { cohort_ID: this.selectedCohort || 0 }
              });
              this.list = r.applications || [];
            } catch (e) {
              // ✅ 這裡你之前看到的 NOT_LOGGED_IN 就會在這裡被抓到
              this.errorMsg = e.message || String(e);
              this.list = [];
            } finally {
              this.loading = false;
            }
          },

          openRemark(app) {
            this.remarkTarget = app;
            this.remarkDraft = app.review_remark || '';
            const modal = new bootstrap.Modal(document.getElementById('remarkModal'));
            modal.show();
          },

          async saveRemark() {
            if (!this.remarkTarget) return;
            try {
              await apiFetch('review_application', {
                method: 'POST',
                body: new URLSearchParams({
                  tap_ID: this.remarkTarget.tap_ID,
                  action: 'save_remark',
                  remark: this.remarkDraft
                })
              });
              this.remarkTarget.review_remark = this.remarkDraft;

              Swal.fire({ icon: 'success', title: '已儲存備註', timer: 1100, showConfirmButton: false });
              bootstrap.Modal.getInstance(document.getElementById('remarkModal'))?.hide();
            } catch (e) {
              Swal.fire({ icon: 'error', title: '儲存失敗', text: e.message });
            }
          },

          async approve(app) {
            const { isConfirmed } = await Swal.fire({
              icon: 'question',
              title: '確定通過？',
              text: '通過後會建立團隊並通知成員與老師。',
              showCancelButton: true,
              confirmButtonText: '通過',
              cancelButtonText: '取消'
            });
            if (!isConfirmed) return;

            try {
              await apiFetch('review_application', {
                method: 'POST',
                body: new URLSearchParams({
                  tap_ID: app.tap_ID,
                  action: 'approve',
                  remark: app.review_remark || ''
                })
              });
              Swal.fire({ icon: 'success', title: '已通過', timer: 1200, showConfirmButton: false });
              await this.loadApplications();
            } catch (e) {
              Swal.fire({ icon: 'error', title: '通過失敗', text: e.message });
            }
          },

          async reject(app) {
            const { value, isConfirmed } = await Swal.fire({
              icon: 'warning',
              title: '退件原因',
              input: 'textarea',
              inputValue: app.review_remark || '',
              inputPlaceholder: '請輸入退件原因（會寫入備註並寄出通知）',
              showCancelButton: true,
              confirmButtonText: '退件',
              cancelButtonText: '取消'
            });
            if (!isConfirmed) return;

            try {
              await apiFetch('review_application', {
                method: 'POST',
                body: new URLSearchParams({
                  tap_ID: app.tap_ID,
                  action: 'reject',
                  remark: value || ''
                })
              });
              Swal.fire({ icon: 'success', title: '已退件', timer: 1200, showConfirmButton: false });
              await this.loadApplications();
            } catch (e) {
              Swal.fire({ icon: 'error', title: '退件失敗', text: e.message });
            }
          },

          async openTeacherTeams(teacherId, teacherName) {
            this.teacherModal.id = teacherId;
            this.teacherModal.name = teacherName || teacherId;
            this.teacherModal.loading = true;
            this.teacherModal.error = '';
            this.teacherModal.teams = [];

            const modal = new bootstrap.Modal(document.getElementById('teacherModal'));
            modal.show();

            try {
              const r = await apiFetch('get_teacher_teams', { query: { teacher_id: teacherId } });
              this.teacherModal.teams = r.teams || [];
            } catch (e) {
              this.teacherModal.error = e.message || String(e);
            } finally {
              this.teacherModal.loading = false;
            }
          }
        },
        mounted() {
          // 刷新按鈕（非 Vue 的那顆）
          document.getElementById('btnRefresh')?.addEventListener('click', () => this.loadApplications());
          this.init();
        }
      });

      app.mount('#reviewApp');
    };
  })();
