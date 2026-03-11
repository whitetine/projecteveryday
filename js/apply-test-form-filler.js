window.mountApplyTestFormFiller = function (mountSelector) {
  const app = Vue.createApp({
    data() {
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
      return {
        selectedFileID: '',
        applyUser: userName,
        applyOther: '',
        files: [],
        selectedForm: null,
        formAnswers: {},
        loading: false,
        draftSaved: false,
        projectData: {
          has_team: false,
          project_title: '',
          students: [],
          advisor: ''
        },
        submittedAt: '',
        supplementPdfFile: null,
        supplementPdfFileName: '',
        draftAttachName: '',
        draftAttachUrl: '',
        draftSubId: null,
        draftLastUpdated: '',
        signPdfFile: null,
        signPdfFileName: '',
        signPdfPreviewUrl: '', // 選檔後不按暫存也可預覽（blob URL），關閉或切換表單時會 revoke
        draftSignName: '',
        draftSignUrl: '',
        versionVerified: false,
        versionMessage: '',
        questionImagePreview: {},
        questionImageFiles: {},
        questionImageInputRefs: {},
        // 表格題型編輯狀態（列數、欄數與各格內容）
        tableStates: {},
        // 對比視窗相關
        showCompareModal: false,
        compareData: {
          originalPdfPath: null,
          signPath: null,
          qrModifiedAt: null,
          systemLastModified: null,
          verifyResult: null,
          verifyNote: null,
          submissionId: null
        },
        compareDataLoading: false,
        isSubmitted: false,
        compareSignIframeT: 0, // 簽名 PDF iframe 防快取用，開對比視窗時更新
        compareLeftPdfT: 0,     // 規則 3：簽名前 PDF iframe 進入核實視窗時強制重產（cache-bust）
        supplementEnabled: true,
        supplementNote: '可上傳一份 PDF 作為表單補充說明。'
      };
    },
    computed: {
      /** 相對路徑 uploads/document_form_signs/xxx.pdf，供 iframe 直接顯示用 */
      sign_path() {
        if (!this.compareData || !this.compareData.signPath) return '';
        return String(this.compareData.signPath).trim().replace(/^\/+/, '');
      },
      selectedFile() {
        if (!this.selectedFileID || !this.files.length) return null;
        return this.files.find(f => String(f.doc_ID) === String(this.selectedFileID)) || null;
      },
      formQuestions() {
        if (!this.selectedForm || !this.selectedForm.form_schema) return [];
        const schema = this.selectedForm.form_schema;
        let questions = [];
        if (Array.isArray(schema)) {
          questions = schema;
        } else if (schema.questions && Array.isArray(schema.questions)) {
          questions = schema.questions;
        }
        return questions.sort((a, b) => (a.order || 0) - (b.order || 0));
      },
      // 僅需填寫的題目（排除專題基本資料區塊與已停用之專題生+指導老師題型，表單頂部固定顯示專題資訊）
      fillableQuestions() {
        return this.formQuestions.filter(q =>
          q.type !== 'project_basic_block' &&
          q.special_field !== 'project_basic' &&
          q.type !== 'students_advisor'
        );
      },
      // 表單規則：是否顯示專題基本資料區塊（僅當表單設計中有此區塊時顯示）
      showProjectBasicBlock() {
        return this.formQuestions.some(q =>
          q.type === 'project_basic_block' || q.special_field === 'project_basic'
        );
      },
      // 表單規則：是否顯示系統資訊（最後修改時間/提交時間，依科辦勾選「匯出 PDF 頁尾自動顯示」）
      showSystemInfo() {
        if (!this.selectedForm) return false;
        const v = this.selectedForm.pdf_footer_timestamps;
        return v === undefined || v === 1 || v === '1';
      },
      // 表單抬頭（在「XX科專題」前自動換行，如：資管科專題初審申請單；多個換行合併，中間不空行）
      displayDocHeader() {
        const h = this.selectedForm && this.selectedForm.doc_header ? String(this.selectedForm.doc_header).trim() : '';
        if (!h) return '';
        return h.replace(/\s+([^\s]*科專題[^\s]*)/gu, '\n$1').replace(/\n{2,}/g, '\n');
      },
      // 是否可編輯審查用欄位（僅指導老師 role 4、班導 role 3 等審查角色可編輯）
      canEditReviewFields() {
        const role = window.CURRENT_USER && window.CURRENT_USER.role_ID != null ? Number(window.CURRENT_USER.role_ID) : null;
        return role !== null && [3, 4].indexOf(role) !== -1;
      },
      canSubmit() {
        if (!this.selectedFile) return false;
        if (this.isSubmitted) return false;
        if (this.selectedFile.doc_end_d && this.isExpired(this.selectedFile.doc_end_d)) {
          return false;
        }
        if (this.selectedFile.doc_start_d && !this.isStarted(this.selectedFile.doc_start_d)) {
          return false;
        }
        // 檢查必填欄位（排除審查用欄位），依題型正確驗證
        for (const q of this.fillableQuestions) {
          if (this.isReviewField(q)) continue;
          if (!q.required) continue;
          if (!this.isQuestionFilled(q)) return false;
        }
        return true;
      },
      // 無論核實一致或不一致皆可提交（按「確認提交」即送出）
      canConfirmSubmit() {
        return this.canSubmit;
      },
      // 附件區塊：截止時間後為唯讀
      attachmentBlockReadOnly() {
        if (!this.selectedFile || !this.selectedFile.doc_end_d) return false;
        return this.isExpired(this.selectedFile.doc_end_d);
      },
      // 單一外框內顯示的檔名：本次選擇優先，否則為已暫存附件名
      displayAttachName() {
        return (this.supplementPdfFileName || this.draftAttachName) || '';
      },
      // 簽名檔顯示檔名（PDF）
      displaySignImageName() {
        return (this.signPdfFileName || this.draftSignName) || '';
      },
      // 系統資訊「最後修改時間」：有暫存時以暫存時間為準，否則為表單版本時間
      // 僅在有暫存/提交記錄時才有「最後修改時間」；未填過表單不顯示表單範本更新日
      displayLastUpdated() {
        return this.draftLastUpdated || '';
      },
      // 簽名前 PDF iframe src：即時產生，100% 顯示，不依賴 original_pdf_path
      compareLeftPdfSrc() {
        if (!this.selectedFileID) return '';
        const pathname = window.location.pathname || '';
        const base = pathname.includes('/pages/') ? '' : 'pages/';
        const t = this.compareLeftPdfT || Date.now();
        return base + 'document_form_pdf_preview.php?doc_ID=' + encodeURIComponent(this.selectedFileID) + '&t=' + t + '#zoom=75';
      },
      // 核實結果顯示用：與科辦端 apply_preview_list 一致。後端 verify_result：1=一致、2=不一致、0=無法核實
      isVerifyConsistent() {
        return this.compareData && this.compareData.verifyResult === 1;
      },
      isVerifyInconsistent() {
        return this.compareData && this.compareData.verifyResult === 2;
      },
      isVerifyPending() {
        return !this.compareData || (this.compareData.verifyResult !== 1 && this.compareData.verifyResult !== 2);
      },
      verifyResultLabel() {
        const vr = this.compareData && this.compareData.verifyResult !== null && this.compareData.verifyResult !== undefined ? parseInt(this.compareData.verifyResult, 10) : null;
        if (vr === 1) return '核實結果一致';
        if (vr === 2) return '不一致';
        if (vr === 0) return '無法核實';
        return '未核實';
      },
      // 簽名後 PDF iframe src：優先 blob 預覽，否則用下載頁或後端路徑
      compareRightPdfSrc() {
        if (this.signPdfPreviewUrl) return this.signPdfPreviewUrl + '#zoom=75';
        const pathname = window.location.pathname || '';
        const base = pathname.includes('/pages/') ? '' : 'pages/';
        if (this.draftSignUrl) {
          const sep = this.draftSignUrl.indexOf('?') >= 0 ? '&' : '?';
          return this.draftSignUrl + sep + 't=' + (this.compareSignIframeT || Date.now()) + '#zoom=75';
        }
        if (this.sign_path) return this.getPdfUrl(this.sign_path) + (this.getPdfUrl(this.sign_path).indexOf('?') >= 0 ? '&' : '?') + 't=' + (this.compareSignIframeT || Date.now()) + '#zoom=75';
        return '';
      }
    },
    watch: {
      selectedFileID: {
        handler(newVal) {
          if (newVal) {
            this.loadFormDetail(newVal);
          } else {
            this.selectedForm = null;
            this.formAnswers = {};
            this.supplementPdfFile = null;
            this.supplementPdfFileName = '';
            if (this.signPdfPreviewUrl) {
              URL.revokeObjectURL(this.signPdfPreviewUrl);
              this.signPdfPreviewUrl = '';
            }
            this.draftAttachName = '';
            this.draftAttachUrl = '';
            this.draftLastUpdated = '';
            this.draftSignName = '';
            this.draftSignUrl = '';
            this.questionImagePreview = {};
            this.questionImageFiles = {};
            this.tableStates = {};
            this.questionImageInputRefs = {};
          }
        }
      },
      showCompareModal: {
        handler(newVal) {
          if (newVal) {
            document.body.classList.add('modal-open');
          } else {
            document.body.classList.remove('modal-open');
          }
        }
      }
    },
    methods: {
      // 是否為審查用欄位（標題含「評分」或「簽名」者，學生不可填、不驗證必填）
      isReviewField(q) {
        if (!q || !q.title) return false;
        const t = String(q.title);
        return t.indexOf('評分') !== -1 || t.indexOf('簽名') !== -1;
      },

      // 審查用欄位顯示標題
      getReviewFieldDisplayTitle(q) {
        if (!q || !q.title) return '';
        return String(q.title).trim() === '指導老師評分與簽名'
          ? '指導老師簽名'
          : q.title;
      },

      // 審查用欄位提示
      getReviewFieldHint(q) {
        if (!q || !q.title) return '（審查用欄位，由指導老師／審查小組填寫）';
        const t = String(q.title).trim();
        if (t === '指導老師評分與簽名' || (t.indexOf('指導老師') !== -1 && t.indexOf('簽名') !== -1))
          return '由指導老師填寫';
        if (t.indexOf('審查小組') !== -1)
          return '（審查用欄位，由審查小組填寫）';
        return '（審查用欄位，由指導老師／審查小組填寫）';
      },

      async lockOriginalPdf() {
        if (!this.draftSubId) {
          alert('請先按「暫存」，產生 sub_ID 後再產生簽名前PDF');
        }

        if (!this.selectedFileID) {
          alert('請先選擇表單類型');
          return;
        }

        // 2026-03：簽名前 PDF 的實際檔案由預覽頁（document_form_pdf_preview.php）內的
        // document_form_pdf.js 自動產生並上傳（呼叫 api.php?do=save_original_pdf）。
        // 這裡只需要強制重載左側 iframe，讓使用者看到最新的簽名前 PDF。
        this.compareLeftPdfT = Date.now();
        alert('已重新產生簽名前PDF，請在左側預覽畫面下載後簽名再上傳。');
      },

      isQuestionFilled(q) {
        const key = `q_${q.order}`;
        if (q.type === 'checkbox') {
          const val = this.formAnswers[key];
          return Array.isArray(val) && val.length > 0;
        }
        if (q.type === 'image_upload') {
          const val = this.formAnswers[key];
          if (typeof val === 'string' && val.trim() !== '') return true;
          const fileKey = 'q_' + q.order;
          return !!(this.questionImageFiles[fileKey] && this.questionImageFiles[fileKey].size > 0);
        }
        const val = this.formAnswers[key];
        if (val === undefined || val === null) return false;
        if (typeof val === 'string' && val.trim() === '') return false;
        if (Array.isArray(val) && val.length === 0) return false;
        return true;
      },
      // 取得 / 初始化指定題目的表格狀態（列數、欄數與每格內容）
      getTableState(key) {
        if (!this.tableStates) this.tableStates = {};
        if (!this.tableStates[key]) {
          this.tableStates[key] = {
            rows: 3,
            cols: 3,
            cells: Array.from({ length: 3 }, () => Array.from({ length: 3 }, () => ''))
          };
        }
        return this.tableStates[key];
      },
      // 讀取表格某一格的內容
      getTableCell(key, rowIndex, colIndex) {
        const state = this.getTableState(key);
        if (!state.cells[rowIndex]) return '';
        return state.cells[rowIndex][colIndex] || '';
      },
      // 學生在表格格子輸入時更新狀態與對應的 HTML 答案字串
      onTableCellInput(key, rowIndex, colIndex, value) {
        const state = this.getTableState(key);
        if (!state.cells[rowIndex]) {
          state.cells[rowIndex] = [];
        }
        state.cells[rowIndex][colIndex] = value;
        this.updateTableAnswerFromState(key);
      },
      // 學生調整列數或欄數時，調整 cells 陣列並同步更新 HTML
      onTableSizeChange(q) {
        const key = `q_${q.order}`;
        const state = this.getTableState(key);
        let rows = Number(state.rows) || 1;
        let cols = Number(state.cols) || 1;
        rows = Math.max(1, Math.min(rows, 20));
        cols = Math.max(1, Math.min(cols, 10));
        const newCells = [];
        for (let r = 0; r < rows; r++) {
          if (state.cells[r]) {
            const row = state.cells[r].slice(0, cols);
            while (row.length < cols) row.push('');
            newCells.push(row);
          } else {
            newCells.push(Array.from({ length: cols }, () => ''));
          }
        }
        state.rows = rows;
        state.cols = cols;
        state.cells = newCells;
        this.updateTableAnswerFromState(key);
      },
      setTableRows(q, val) {
        const key = `q_${q.order}`;
        const state = this.getTableState(key);
        state.rows = Math.max(1, Math.min(parseInt(val, 10) || 1, 20));
        this.onTableSizeChange(q);
      },
      setTableCols(q, val) {
        const key = `q_${q.order}`;
        const state = this.getTableState(key);
        state.cols = Math.max(1, Math.min(parseInt(val, 10) || 1, 10));
        this.onTableSizeChange(q);
      },
      // 依照 tableStates 產生 HTML 表格字串，儲存在 formAnswers 中，供暫存與 PDF 匯出
      updateTableAnswerFromState(key) {
        const state = this.getTableState(key);
        const rows = state.rows || 0;
        const cols = state.cols || 0;
        let html = '<table class="student-answer-table"><tbody>';
        for (let r = 0; r < rows; r++) {
          html += '<tr>';
          for (let c = 0; c < cols; c++) {
            const text = (state.cells[r] && state.cells[r][c]) ? String(state.cells[r][c]) : '';
            html += '<td>' + this.escapeHtml(text) + '</td>';
          }
          html += '</tr>';
        }
        html += '</tbody></table>';
        this.formAnswers = {
          ...this.formAnswers,
          [key]: html
        };
      },
      // 從已存在的 HTML 表格答案初始化 tableStates（重新載入或有既有答案時用）
      initTableStateFromAnswer(q) {
        const key = `q_${q.order}`;
        const raw = this.formAnswers && this.formAnswers[key] ? String(this.formAnswers[key]) : '';
        if (!raw) {
          this.getTableState(key);
          return;
        }
        try {
          const wrapper = document.createElement('div');
          wrapper.innerHTML = raw;
          const rowsEl = wrapper.querySelectorAll('tr');
          if (!rowsEl.length) {
            this.getTableState(key);
            return;
          }
          const cells = [];
          let maxCols = 0;
          rowsEl.forEach(tr => {
            const row = [];
            tr.querySelectorAll('th,td').forEach(td => {
              row.push((td.textContent || '').trim());
            });
            if (row.length > maxCols) maxCols = row.length;
            cells.push(row);
          });
          if (!maxCols) maxCols = 1;
          const normalized = cells.map(row => {
            const r = row.slice(0, maxCols);
            while (r.length < maxCols) r.push('');
            return r;
          });
          this.tableStates[key] = {
            rows: normalized.length,
            cols: maxCols,
            cells: normalized
          };
        } catch (e) {
          console.warn('initTableStateFromAnswer error', e);
          this.getTableState(key);
        }
      },
      // 對目前表單所有表格題型進行初始化
      initAllTableStates() {
        if (!Array.isArray(this.formQuestions) || !this.formQuestions.length) return;
        this.formQuestions.forEach(q => {
          if (q.type === 'table') {
            this.initTableStateFromAnswer(q);
          }
        });
      },
      isExpired(endDate) {
        if (!endDate) return false;
        const now = new Date();
        const end = new Date(endDate);
        return now > end;
      },
      isStarted(startDate) {
        if (!startDate) return true;
        const now = new Date();
        const start = new Date(startDate);
        return now >= start;
      },
      // 取得填寫表單當天的西元日期 YYYY-MM-DD（用於日期欄位預設值）
      getTodayYmd() {
        const d = new Date();
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
      },
      // 西元 YYYY-MM-DD → 民國YYY年M月D日（僅顯示用，資料仍為西元）
      formatDateMinguo(ymdStr) {
        if (!ymdStr || typeof ymdStr !== 'string') return '';
        const m = ymdStr.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (!m) return ymdStr;
        const y = parseInt(m[1], 10);
        const month = parseInt(m[2], 10);
        const day = parseInt(m[3], 10);
        const roc = y - 1911;
        return `民國${roc}年${month}月${day}日`;
      },
      // 西元日期時間 → 民國YYY年M月D日 上午/下午 H:mm（僅顯示用）
      formatDateTimeMinguo(dateTimeStr) {
        if (!dateTimeStr) return '';
        const date = new Date(dateTimeStr);
        if (isNaN(date.getTime())) return dateTimeStr;
        const y = date.getFullYear();
        const roc = y - 1911;
        const month = date.getMonth() + 1;
        const day = date.getDate();
        const h = date.getHours();
        const min = date.getMinutes();
        const ampm = h < 12 ? '上午' : '下午';
        const h12 = h === 0 ? 12 : (h > 12 ? h - 12 : h);
        const minStr = String(min).padStart(2, '0');
        return `民國${roc}年${month}月${day}日 ${ampm}${h12}:${minStr}`;
      },
      formatDateTime(dateTimeStr) {
        return this.formatDateTimeMinguo(dateTimeStr);
      },
      // 將所有日期題型中目前為空的欄位預設成今天日期（YYYY-MM-DD）
      setTodayForEmptyDateQuestions() {
        const today = this.getTodayYmd();
        this.formQuestions.forEach(q => {
          if (q.type !== 'date') return;
          const key = `q_${q.order}`;
          if (!this.formAnswers[key]) {
            this.formAnswers[key] = today;
          }
        });
      },
      // 將指定日期題目重置為今天（使用者按「重置為今天」時呼叫）
      resetDateToToday(key) {
        const today = this.getTodayYmd();
        this.formAnswers = { ...this.formAnswers, [key]: today };
        // 同步更新對應 input 顯示的民國日期
        this.$nextTick(() => {
          const input = document.querySelector(`.date-picker-input[data-key="${key}"]`);
          if (input) {
            input.value = this.formatDateMinguo(today);
          }
        });
      },
      // 標題含概述、架構、至少六百字、進度說明等視為長文題，用多行 textarea 顯示完整並保留換行
      // 標題含評分、簽名、概述、分工等 → 以多行 textarea 呈現，可預覽多行內容（參考 form_manage 與圖2～圖4）
      useTextareaForQuestion(q) {
        if (!q || q.type === 'textarea') return true;
        if (q.type !== 'text') return false;
        const t = (q.title || '').trim();
        return /概述|架構|至少六百字|進度說明|分工及進度|參考文獻|預期完成|工作分配|評分|簽名|審查/i.test(t);
      },
      getTextareaRows(q) {
        if (q.textarea_display === 'large') return q.rows || 20;
        // 審查/簽名欄位：不用那麼大，約 4 行即可
        if (this.isReviewField(q)) return 4;
        if (this.useTextareaForQuestion(q) && q.type === 'text') return 12;
        return q.rows || 5;
      },
      onSupplementPdfChange(e) {
        const input = e.target;
        const file = input && input.files && input.files[0];
        if (!file) {
          this.supplementPdfFile = null;
          this.supplementPdfFileName = '';
          return;
        }
        const name = (file.name || '').toLowerCase();
        if (!name.endsWith('.pdf') && file.type !== 'application/pdf') {
          Swal.fire('錯誤', '僅允許上傳 PDF 檔案', 'error');
          input.value = '';
          this.supplementPdfFile = null;
          this.supplementPdfFileName = '';
          return;
        }
        if (input.files.length > 1) {
          Swal.fire('提示', '僅限上傳 1 份 PDF，已取第一份', 'info');
          input.value = '';
        }
        this.supplementPdfFile = file;
        this.supplementPdfFileName = file.name || '附件.pdf';
      },
      // 重置選檔：清空記憶體與 file input，中間欄位會還原為已暫存檔名或「未選擇檔案」
      resetAttachmentInput() {
        this.supplementPdfFile = null;
        this.supplementPdfFileName = '';
        const input = this.$refs.supplementPdfInput;
        if (input) {
          input.value = '';
          try { input.value = ''; } catch (e) { }
        }
        this.$nextTick(() => {
          const el = this.$refs.supplementPdfInput;
          if (el) { el.value = ''; }
        });
      },
      // 附件區重置：清空本地與暫存顯示，並通知後端清除暫存附件，刷新後不會再出現
      async resetAttachment() {
        this.supplementPdfFile = null;
        this.supplementPdfFileName = '';
        this.draftAttachName = '';
        this.draftAttachUrl = '';
        this.$nextTick(() => {
          const el = this.$refs.supplementPdfInput;
          if (el) el.value = '';
        });
        if (this.selectedFileID) await this.saveDraftWithClear('attachment');
      },
      // 簽名區重置：清空本地與暫存顯示、核實結果，並通知後端清除暫存簽名，刷新後不會再出現
      async resetSignature() {
        this.signPdfFile = null;
        this.signPdfFileName = '';
        this.draftSignName = '';
        this.draftSignUrl = '';
        this.versionVerified = false;
        this.versionMessage = '';
        if (this.compareData) {
          this.compareData.verifyResult = null;
          this.compareData.qrModifiedAt = null;
          this.compareData.systemLastModified = null;
        }
        if (this.signPdfPreviewUrl) {
          URL.revokeObjectURL(this.signPdfPreviewUrl);
          this.signPdfPreviewUrl = '';
        }
        this.$nextTick(() => {
          const el = this.$refs.signPdfInput;
          if (el) el.value = '';
        });
        if (this.selectedFileID) await this.saveDraftWithClear('sign');
      },
      // 呼叫暫存 API 並帶入 clear_attachment 或 clear_sign，使後端清除對應欄位（刷新後不會回填）
      async saveDraftWithClear(which) {
        let API_ROOT = 'api.php';
        const pathname = window.location.pathname || '';
        if (pathname.includes('/pages/')) API_ROOT = '../api.php';
        const formData = new FormData();
        formData.append('document_id', this.selectedFileID);
        formData.append('apply_user', this.applyUser || '');
        formData.append('apply_other', this.applyOther || '');
        formData.append('form_answers', JSON.stringify(this.formAnswers));
        if (which === 'attachment') formData.append('clear_attachment', '1');
        if (which === 'sign') formData.append('clear_sign', '1');
        try {
          const res = await fetch(API_ROOT + '?do=save_document_form_draft', { method: 'POST', body: formData });
          const data = await res.json();
          if (data.ok && data.dcsu_updated_d) this.draftLastUpdated = String(data.dcsu_updated_d).trim();
          if (data.ok && data.sub_ID > 0) this.draftSubId = data.sub_ID;
        } catch (e) {
          console.warn('saveDraftWithClear failed', e);
        }
      },
      triggerAttachmentInput() {
        this.$nextTick(() => {
          if (this.$refs.supplementPdfInput) this.$refs.supplementPdfInput.click();
        });
      },
      onSignPdfChange(e) {
        const input = e.target;
        const file = input && input.files && input.files[0];
        if (this.signPdfPreviewUrl) {
          URL.revokeObjectURL(this.signPdfPreviewUrl);
          this.signPdfPreviewUrl = '';
        }
        if (!file) {
          this.signPdfFile = null;
          this.signPdfFileName = '';
          return;
        }
        const name = (file.name || '').toLowerCase();
        if (!name.endsWith('.pdf') && file.type !== 'application/pdf') {
          Swal.fire('錯誤', '簽名檔僅允許 PDF 格式', 'error');
          input.value = '';
          this.signPdfFile = null;
          this.signPdfFileName = '';
          return;
        }
        this.signPdfFile = file;
        this.signPdfFileName = file.name || 'sign.pdf';
        this.signPdfPreviewUrl = URL.createObjectURL(file);
        this.versionVerified = false;
        this.versionMessage = '';

        // 規則調整：學生簽名後「不再按暫存」，改為選檔當下即上傳並進行版本比對
        this.uploadSignPdfForVerification().catch(err => {
          console.error('uploadSignPdfForVerification error:', err);
          Swal.fire('錯誤', '簽名 PDF 上傳或比對失敗，請稍後再試', 'error');
        });
      },
      triggerSignPdfInput() {
        this.$nextTick(() => {
          if (this.$refs.signPdfInput) this.$refs.signPdfInput.click();
        });
      },
      getFileOptionText(file) {
        let text = file.doc_name || '未知文件';
        if (file.is_required == 1) {
          text += '（必填）';
        }

        const timeParts = [];
        if (file.doc_start_d) {
          timeParts.push('開放：' + this.formatDateTime(file.doc_start_d));
        }
        if (file.doc_end_d) {
          timeParts.push('截止：' + this.formatDateTime(file.doc_end_d));
        }

        if (timeParts.length > 0) {
          text += ' - ' + timeParts.join(' | ');
        }

        return text;
      },
      async loadFormDetail(documentId) {
        try {
          this.loading = true;
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          }

          const res = await fetch(`${API_ROOT}?do=get_document_form_detail_student&document_id=${documentId}`);
          const data = await res.json();

          if (data.ok && data.form) {
            // 防呆檢查：再次驗證表單是否可用（時間檢查）
            const now = new Date();
            if (data.form.doc_start_d) {
              const startDate = new Date(data.form.doc_start_d);
              if (now < startDate) {
                Swal.fire({
                  icon: 'warning',
                  title: '表單尚未開放',
                  html: `此表單將於 ${this.formatDateTime(data.form.doc_start_d)} 開放`,
                  confirmButtonText: '確定',
                  confirmButtonColor: '#667eea'
                });
                this.selectedFileID = '';
                this.loading = false;
                return;
              }
            }

            if (data.form.doc_end_d) {
              const endDate = new Date(data.form.doc_end_d);
              if (now > endDate) {
                Swal.fire({
                  icon: 'error',
                  title: '表單已過期',
                  html: `此表單已於 ${this.formatDateTime(data.form.doc_end_d)} 截止`,
                  confirmButtonText: '確定',
                  confirmButtonColor: '#667eea'
                });
                this.selectedFileID = '';
                this.loading = false;
                return;
              }
            }

            this.selectedForm = data.form;
            this.supplementPdfFile = null;
            this.supplementPdfFileName = '';
            this.draftAttachName = '';
            this.draftAttachUrl = '';
            this.signPdfFile = null;
            this.signPdfFileName = '';
            if (this.signPdfPreviewUrl) {
              URL.revokeObjectURL(this.signPdfPreviewUrl);
              this.signPdfPreviewUrl = '';
            }
            this.draftSignName = '';
            this.draftSignUrl = '';
            this.versionVerified = false;
            this.versionMessage = '';
            if (this.$refs.supplementPdfInput) this.$refs.supplementPdfInput.value = '';
            if (this.$refs.signPdfInput) this.$refs.signPdfInput.value = '';
            // 補充附件設定：由表單 schema 控制是否顯示與說明文字
            const se = data.form.supplement_attachment_enabled;
            this.supplementEnabled = (se === undefined || se === null) ? true : !!parseInt(se, 10);
            this.supplementNote = data.form.supplement_attachment_note || '可上傳一份 PDF 作為表單補充說明。';

            // 專題基本資料由 API 一併回傳，學生端自動帶入、唯讀，不需學生操作
            if (data.project_data) {
              this.projectData = {
                has_team: !!data.project_data.has_team,
                project_title: data.project_data.project_title || '',
                students: data.project_data.students || [],
                advisor: data.project_data.advisor || ''
              };
            } else {
              this.projectData = { has_team: false, project_title: '', students: [], advisor: '' };
            }
            // 切換 doc_ID 時先 reset formAnswers 再載入；載入順序：DB 優先，DB 無資料才讀 localStorage
            this.formAnswers = {};
            this.questionImagePreview = {};
            this.questionImageFiles = {};
            // 依 schema 初始化預設答案
            const newAnswers = {};
            const questions = this.formQuestions;
            questions.forEach(q => {
              if (q.type === 'project_basic_block' || q.special_field === 'project_basic' || q.type === 'students_advisor') return;
              const key = `q_${q.order}`;
              if (q.type === 'checkbox') {
                newAnswers[key] = [];
              } else if (q.type === 'image_upload') {
                newAnswers[key] = '';
              } else if (q.type === 'date') {
                newAnswers[key] = this.getTodayYmd();
              } else {
                newAnswers[key] = '';
              }
            });
            this.formAnswers = { ...newAnswers };

            const hadDbDraft = await this.loadDraftAttachment();
            if (!hadDbDraft) this.restoreDraft();
            this.setTodayForEmptyDateQuestions();
            this.initAllTableStates();

            // 檢查是否已提交（dcsub_status = 'submitted'），一份表單只能提交一次，提交後唯讀
            if (data.submission_status === 'submitted' || data.submission_status === true) {
              this.isSubmitted = true;
              this.submittedAt = data.submitted_at ? this.formatDateTime(data.submitted_at) : '已提交';
            } else {
              this.isSubmitted = false;
              this.submittedAt = '';
            }

            // 初始化日期选择器（延迟一下确保 DOM 已更新）
            this.$nextTick(() => {
              setTimeout(() => {
                this.initDatePickers();
              }, 100);
            });
          } else {
            // 防呆：顯示詳細的錯誤訊息
            let errorMsg = data.msg || '載入表單失敗';
            if (data.msg && data.msg.includes('權限')) {
              errorMsg = '您沒有權限查看此表單。此表單可能僅限特定學級、班級或類組的學生填寫。';
            } else if (data.msg && data.msg.includes('尚未開放')) {
              errorMsg = '此表單尚未開放，請稍後再試。';
            } else if (data.msg && data.msg.includes('已過期')) {
              errorMsg = '此表單已過期，無法填寫。';
            }

            Swal.fire({
              icon: 'error',
              title: '無法載入表單',
              text: errorMsg,
              confirmButtonText: '確定',
              confirmButtonColor: '#667eea'
            });

            // 重置選擇
            this.selectedFileID = '';
            this.selectedForm = null;
          }
        } catch (e) {
          console.error('載入表單詳情錯誤:', e);
          Swal.fire('錯誤', '無法載入表單詳情', 'error');
        } finally {
          this.loading = false;
        }
      },
      getProjectStudents(questionOrder) {
        // 優先從 projectData 獲取專題生資料
        if (this.projectData && this.projectData.students && this.projectData.students.length > 0) {
          return this.projectData.students.map(s => ({
            id: s.student_id || s.u_ID || '',
            name: s.u_name || ''
          }));
        }

        // 如果 projectData 沒有資料，從 formAnswers 中獲取
        const key = `q_${questionOrder}`;
        const students = [];
        let index = 0;

        // 檢查是否存在專題生欄位
        while (this.formAnswers.hasOwnProperty(`${key}_student_${index}_id`)) {
          const studentId = this.formAnswers[`${key}_student_${index}_id`] || '';
          const studentName = this.formAnswers[`${key}_student_${index}_name`] || '';
          if (studentId || studentName) {
            students.push({
              id: studentId,
              name: studentName
            });
          }
          index++;
        }

        return students;
      },
      async prefillProjectData(questions) {
        try {
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          }

          const res = await fetch(`${API_ROOT}?do=get_project_data_for_student`);
          const data = await res.json();

          if (data.ok && data.has_team) {
            const students = data.students || [];
            const advisor = data.advisor || '';

            // 保存專題資料到 projectData
            this.projectData = {
              students: students,
              advisor: advisor
            };

            // 遍歷所有 students_advisor 類型的題目
            questions.forEach(q => {
              if (q.type === 'students_advisor' &&
                (q.prefill_source === 'by_project' || q.prefill_source === 'mixed')) {
                const key = `q_${q.order}`;

                // 填入專題生資料（動態根據實際專題生數量）
                if (Array.isArray(students) && students.length > 0) {
                  // 使用 Vue 3 的響應式更新方式
                  const updatedAnswers = { ...this.formAnswers };

                  students.forEach((student, si) => {
                    const studentIdKey = `${key}_student_${si}_id`;
                    const studentNameKey = `${key}_student_${si}_name`;

                    // 動態創建專題生欄位（如果不存在）
                    // 優先使用 student_id (u_account)，如果沒有則使用 u_ID
                    updatedAnswers[studentIdKey] = student.student_id || student.u_ID || '';
                    updatedAnswers[studentNameKey] = student.u_name || '';
                  });

                  // 更新整個 formAnswers 對象以確保響應式
                  this.formAnswers = updatedAnswers;
                }

                // 填入指導老師
                if (advisor) {
                  // 使用 Vue 3 的響應式更新方式
                  this.formAnswers = {
                    ...this.formAnswers,
                    [`${key}_advisor`]: advisor
                  };
                }
              }
            });
          } else {
            // 如果沒有專題資料，清空 projectData
            this.projectData = {
              students: [],
              advisor: ''
            };
          }
        } catch (e) {
          console.error('獲取專題資料錯誤:', e);
          // 不顯示錯誤提示，因為這不是必須的
        }
      },
      initDatePickers() {
        const self = this;
        if (typeof flatpickr !== 'undefined') {
          document.querySelectorAll('.date-picker-input').forEach(input => {
            const key = input.getAttribute('data-key');
            if (!key) return;
            if (input._flatpickr) {
              input._flatpickr.destroy();
            }
            const currentValue = self.formAnswers[key] || '';
            // 顯示層：輸入框顯示民國年
            input.value = self.formatDateMinguo(currentValue);
            flatpickr(input, {
              locale: 'zh_tw',
              dateFormat: 'Y-m-d',
              allowInput: false,
              clickOpens: true,
              defaultDate: currentValue || null,
              onChange: function (selectedDates, dateStr, instance) {
                if (dateStr && key) {
                  self.formAnswers = { ...self.formAnswers, [key]: dateStr };
                  input.value = self.formatDateMinguo(dateStr);
                }
              }
            });
          });
        } else {
          setTimeout(() => this.initDatePickers(), 500);
        }
      },
      renderQuestion(q, index) {
        const key = `q_${q.order}`;
        const required = q.required ? 'required' : '';
        const requiredClass = q.required ? 'required' : '';

        let inputHtml = '';

        switch (q.type) {
          case 'text':
            inputHtml = `
              <input type="text" 
                     v-model="formAnswers['${key}']"
                     class="form-control" 
                     placeholder="${this.escapeHtml(q.placeholder || '')}"
                     ${required}>
            `;
            break;

          case 'textarea': {
            const isLarge = q.textarea_display === 'large';
            const rows = q.rows || (isLarge ? 20 : 5);
            const largeClass = isLarge ? ' textarea-large' : '';
            inputHtml = `
              <textarea v-model="formAnswers['${key}']"
                        class="form-control${largeClass}"
                        rows="${rows}"
                        placeholder="${this.escapeHtml(q.placeholder || '')}"
                        ${required}></textarea>
            `;
            break;
          }

          case 'date':
            inputHtml = `
              <div class="input-group">
                <input type="text" 
                       v-model="formAnswers['${key}']"
                       class="form-control date-picker-input" 
                       data-key="${key}"
                       placeholder="請選擇日期"
                       readonly
                       ${required}>
                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        @click="resetDateToToday('${key}')">
                  重置為今天
                </button>
              </div>
            `;
            break;

          case 'radio':
            const radioOptions = Array.isArray(q.options) ? q.options : [];
            inputHtml = `
              <div class="options-container">
                ${radioOptions.map((opt, optIdx) => `
                  <div class="option-item">
                    <label>
                      <input type="radio" 
                             v-model="formAnswers['${key}']"
                             value="${this.escapeHtml(opt)}"
                             ${required}>
                      ${this.escapeHtml(opt)}
                    </label>
                  </div>
                `).join('')}
              </div>
            `;
            break;

          case 'checkbox':
            const checkboxOptions = Array.isArray(q.options) ? q.options : [];
            inputHtml = `
              <div class="options-container">
                ${checkboxOptions.map((opt, optIdx) => `
                  <div class="option-item">
                    <label>
                      <input type="checkbox" 
                             :value="${this.escapeHtml(opt)}"
                             @change="updateCheckbox('${key}', '${this.escapeHtml(opt)}', $event)"
                             ${this.formAnswers[key] && this.formAnswers[key].includes(opt) ? 'checked' : ''}>
                      ${this.escapeHtml(opt)}
                    </label>
                  </div>
                `).join('')}
              </div>
            `;
            break;

          case 'table':
            inputHtml = `
              <div class="table-editor">
                <p class="text-muted small mb-2">您可以從 Excel/Word 複製表格貼上，或直接在下方編輯</p>
                <textarea v-model="formAnswers['${key}']"
                          class="form-control" 
                          rows="8"
                          placeholder="請輸入表格內容（支援 HTML 表格格式）"
                          ${required}></textarea>
              </div>
            `;
            break;

          case 'students_advisor':
            // apply_test：專題生、指導老師改為手動輸入（不自動帶入）
            const students = this.getProjectStudents(q.order);
            const studentsHtml = (students.length > 0 ? students : [{ id: '', name: '' }]).map((s, si) => `
              <div class="student-row mb-2">
                <input type="text" 
                       v-model="formAnswers['${key}_student_${si}_id']"
                       class="form-control d-inline-block" 
                       style="width: 48%; margin-right: 2%;"
                       placeholder="學號">
                <input type="text" 
                       v-model="formAnswers['${key}_student_${si}_name']"
                       class="form-control d-inline-block" 
                       style="width: 48%;"
                       placeholder="姓名">
              </div>
            `).join('');

            inputHtml = `
              <div class="students-advisor-block">
                <div class="mb-3">
                  <label class="form-label">專題生：</label>
                  ${studentsHtml}
                </div>
                <div class="mb-3">
                  <label class="form-label">指導老師：</label>
                  <input type="text" 
                         v-model="formAnswers['${key}_advisor']"
                         class="form-control" 
                         placeholder="請輸入指導老師姓名"
                         ${required}>
                </div>
              </div>
            `;
            break;

          default:
            inputHtml = `
              <input type="text" 
                     v-model="formAnswers['${key}']"
                     class="form-control" 
                     ${required}>
            `;
        }

        return `
          <div class="question-group">
            <label class="question-label ${requiredClass}">
              ${index + 1}. ${this.escapeHtml(q.title || '')}
            </label>
            ${inputHtml}
          </div>
        `;
      },
      updateCheckbox(key, value, event) {
        if (!this.formAnswers[key]) {
          this.formAnswers[key] = [];
        }
        if (event.target.checked) {
          if (!this.formAnswers[key].includes(value)) {
            this.formAnswers[key] = [...this.formAnswers[key], value];
          }
        } else {
          this.formAnswers[key] = this.formAnswers[key].filter(v => v !== value);
        }
      },
      getSubCheckboxChecked(qOrder, si, opt) {
        const key = 'q_' + qOrder + '_sub_' + si;
        let val = this.formAnswers[key];
        if (val == null) return false;
        if (Array.isArray(val)) return val.includes(opt);
        if (typeof val === 'string' && val.trim().indexOf('[') === 0) {
          try {
            const arr = JSON.parse(val);
            return Array.isArray(arr) && arr.includes(opt);
          } catch (e) {
            return val === opt;
          }
        }
        return val === opt;
      },
      updateSubCheckbox(qOrder, si, opt, event) {
        const key = 'q_' + qOrder + '_sub_' + si;
        let arr = this.formAnswers[key];
        if (!Array.isArray(arr)) {
          if (arr != null && typeof arr === 'string' && arr.trim().indexOf('[') === 0) {
            try {
              arr = JSON.parse(arr);
            } catch (e) {
              arr = arr ? [arr] : [];
            }
          } else {
            arr = arr != null && arr !== '' ? [arr] : [];
          }
        }
        if (event.target.checked) {
          if (!arr.includes(opt)) {
            this.formAnswers[key] = [...arr, opt];
          }
        } else {
          this.formAnswers[key] = arr.filter(v => v !== opt);
        }
      },
      setQuestionImageInputRef(order, el) {
        if (!el) return;
        if (!this.questionImageInputRefs) this.questionImageInputRefs = {};
        this.questionImageInputRefs['q_' + order] = el;
      },
      onQuestionFileChange(q, event) {
        const file = event.target && event.target.files && event.target.files[0];
        const key = `q_${q.order}`;
        if (!file) {
          this.formAnswers = { ...this.formAnswers, [key]: '' };
          return;
        }
        this.formAnswers = { ...this.formAnswers, [key]: file.name || '檔案' };
        if (!this.questionFileRefs) this.questionFileRefs = {};
        this.questionFileRefs[key] = file;
      },
      onQuestionImageChange(q, event) {
        const file = event.target && event.target.files && event.target.files[0];
        const fileKey = 'q_' + q.order;
        const maxMb = (q.max_size_mb != null && q.max_size_mb > 0) ? q.max_size_mb : 5;
        const maxBytes = maxMb * 1024 * 1024;
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (this.questionImagePreview[fileKey]) {
          URL.revokeObjectURL(this.questionImagePreview[fileKey]);
          this.questionImagePreview[fileKey] = null;
        }
        this.questionImageFiles[fileKey] = null;
        if (!file) return;
        if (!allowedTypes.includes(file.type) && !/\.(jpe?g|png|webp)$/i.test(file.name)) {
          Swal.fire('格式錯誤', '僅允許 jpg / png / webp', 'warning');
          event.target.value = '';
          return;
        }
        if (file.size > maxBytes) {
          Swal.fire('檔案過大', `單檔請在 ${maxMb}MB 以內`, 'warning');
          event.target.value = '';
          return;
        }
        this.questionImageFiles[fileKey] = file;
        this.questionImagePreview[fileKey] = URL.createObjectURL(file);
      },
      getQuestionImageUrl(path) {
        if (!path || typeof path !== 'string') return '';
        const pathname = window.location.pathname || '';
        const base = pathname.includes('/pages/') ? '../' : '';
        return base + path;
      },
      escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      },
      getDraftKey() {
        // 草稿 key 一律為 apply_draft_${u_ID}_${doc_ID}，綁定使用者與表單，避免 TRUNCATE DB 後仍被舊本機資料回填
        const u_ID = window.CURRENT_USER && window.CURRENT_USER.u_ID != null
          ? String(window.CURRENT_USER.u_ID)
          : '';
        const doc_ID = this.selectedFileID != null && this.selectedFileID !== ''
          ? String(this.selectedFileID)
          : '';
        if (!u_ID || !doc_ID) return null;
        return `apply_draft_${u_ID}_${doc_ID}`;
      },
      /** 從 DB 載入草稿（附件／簽名／form_answers）。回傳 true 表示 DB 有草稿並已合併，false 表示無 DB 草稿。 */
      async loadDraftAttachment() {
        if (!this.selectedFileID) return false;
        const pathname = window.location.pathname || '';
        let API_ROOT = 'api.php';
        if (pathname.includes('main.php')) API_ROOT = 'api.php';
        else if (pathname.includes('/pages/')) API_ROOT = '../api.php';
        const url = API_ROOT + '?do=get_document_form_draft&document_id=' + encodeURIComponent(this.selectedFileID);
        try {
          const res = await fetch(url, { cache: 'no-store', credentials: 'include' });
          if (!res.ok) {
            this.draftLastUpdated = '';
            this.draftAttachName = '';
            this.draftAttachUrl = '';
            this.draftSignName = '';
            this.draftSignUrl = '';
            return false;
          }
          const data = await res.json();
          if (data.ok && data.draft) {
            this.draftSubId = (data.draft.sub_ID > 0) ? data.draft.sub_ID : null;
            if (data.draft.form_answers && typeof data.draft.form_answers === 'object') {
              this.formAnswers = { ...this.formAnswers, ...data.draft.form_answers };
              this.normalizeSubQuestionsFormAnswers();
            }
            this.draftLastUpdated = (data.draft.dcsu_updated_d || '').trim();
            if (data.draft.attach_name || data.draft.attach_path) {
              // 優先使用後端紀錄的原始檔名；若沒有，則從路徑取出實際檔名，避免顯示成通用的「附件.pdf」
              const rawName = (data.draft.attach_name !== undefined && data.draft.attach_name !== null)
                ? String(data.draft.attach_name).trim()
                : '';
              if (rawName) {
                this.draftAttachName = rawName;
              } else if (data.draft.attach_path) {
                const pathStr = String(data.draft.attach_path);
                const parts = pathStr.split(/[/\\]/);
                this.draftAttachName = (parts[parts.length - 1] || '').trim() || '附件.pdf';
              } else {
                this.draftAttachName = '';
              }
              const base = pathname.includes('/pages/') ? '' : 'pages/';
              this.draftAttachUrl = base + 'download_document_form_draft_attachment.php?doc_ID=' + encodeURIComponent(this.selectedFileID);
            } else {
              this.draftAttachName = '';
              this.draftAttachUrl = '';
            }
            if (data.draft.sign_name || data.draft.sign_path) {
              const name = String(data.draft.sign_name || '').trim();
              this.draftSignName = name || (data.draft.sign_path ? (data.draft.sign_path.split(/[/\\]/).pop() || '簽名.pdf') : '簽名圖檔');
              const base = pathname.includes('/pages/') ? '' : 'pages/';
              this.draftSignUrl = base + 'download_document_form_sign.php?doc_ID=' + encodeURIComponent(this.selectedFileID);
            } else {
              this.draftSignName = '';
              this.draftSignUrl = '';
            }
            this.versionVerified = !!data.draft.version_verified;
            this.versionMessage = String(data.draft.version_message || '').trim();
            return true;
          } else {
            this.draftSubId = null;
            this.draftLastUpdated = '';
            this.draftAttachName = '';
            this.draftAttachUrl = '';
            this.draftSignName = '';
            this.draftSignUrl = '';
            this.versionVerified = false;
            this.versionMessage = '';
            return false;
          }
        } catch (e) {
          this.draftSubId = null;
          this.draftLastUpdated = '';
          this.draftAttachName = '';
          this.draftAttachUrl = '';
          this.draftSignName = '';
          this.draftSignUrl = '';
          this.versionVerified = false;
          this.versionMessage = '';
          return false;
        }
      },
      normalizeSubQuestionsFormAnswers() {
        const questions = this.formQuestions || [];
        questions.forEach(q => {
          if (q.type !== 'sub_questions') return;
          const key = 'q_' + q.order;
          const main = this.formAnswers[key];
          if (main !== undefined && main !== null && String(main).trim() !== '') return;
          const subKeys = Object.keys(this.formAnswers).filter(k => k.startsWith(key + '_sub_'));
          if (subKeys.length === 0) return;
          const indices = subKeys.map(k => parseInt(k.replace(key + '_sub_', ''), 10)).filter(n => !isNaN(n)).sort((a, b) => a - b);
          const lines = indices.map(i => this.formAnswers[key + '_sub_' + i]).filter(v => v != null).map(v => String(v).trim());
          this.formAnswers[key] = lines.join('\n');
        });
      },
      restoreDraft() {
        const key = this.getDraftKey();
        if (!key) return;
        try {
          const raw = localStorage.getItem(key);
          if (!raw) return;
          const draft = JSON.parse(raw);
          if (draft && typeof draft === 'object') {
            if (draft.form_answers && typeof draft.form_answers === 'object') {
              this.formAnswers = { ...this.formAnswers, ...draft.form_answers };
              this.normalizeSubQuestionsFormAnswers();
            }
            if (draft.apply_user !== undefined) this.applyUser = String(draft.apply_user);
            if (draft.apply_other !== undefined) this.applyOther = String(draft.apply_other);
          }
        } catch (e) {
          console.warn('restoreDraft parse error', e);
        }
      },

      async saveDraft() {
        if (!this.selectedFileID) {
          Swal.fire('提示', '請先選擇表單', 'info');
          return;
        }
        const key = this.getDraftKey();
        if (!key) return;
        const self = this;
        let skipDraftToast = false;
        let API_ROOT = 'api.php';
        const pathname = window.location.pathname || '';
        if (pathname.includes('/pages/')) API_ROOT = '../api.php';
        const formData = new FormData();
        formData.append('document_id', this.selectedFileID);
        formData.append('apply_user', this.applyUser);
        formData.append('apply_other', this.applyOther);
        formData.append('form_answers', JSON.stringify(this.formAnswers));
        if (this.supplementPdfFile) {
          formData.append('supplement_pdf', this.supplementPdfFile, this.supplementPdfFileName || 'supplement.pdf');
        }
        this.fillableQuestions.forEach(q => {
          if (q.type === 'image_upload') {
            const fileKey = 'q_' + q.order;
            const file = this.questionImageFiles[fileKey];
            if (file && file.size > 0) {
              formData.append('question_image_' + q.order, file, file.name || 'image');
            }
          }
        });
        try {
          const res = await fetch(API_ROOT + '?do=save_document_form_draft', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            Swal.fire('錯誤', data.msg || '暫存失敗', 'error');
            return;
          }
          if (data.dcsu_updated_d) {
            self.draftLastUpdated = String(data.dcsu_updated_d).trim();
          }
          if (data.sub_ID > 0) {
            self.draftSubId = data.sub_ID;
          }
          // 暫存後立即更新快照與版本訊息，不必 F5 即可上傳簽名檔、核實
          if (data.compare_data) {
            self.compareData = {
              originalPdfPath: data.compare_data.original_pdf_path || null,
              signPath: data.compare_data.sign_path || null,
              qrModifiedAt: data.compare_data.qr_modified_at || null,
              systemLastModified: data.compare_data.system_last_modified || null,
              verifyResult: data.compare_data.verify_result !== null && data.compare_data.verify_result !== undefined ? parseInt(data.compare_data.verify_result) : null,
              verifyNote: data.compare_data.verify_note || null,
              submissionId: data.compare_data.submission_id != null ? parseInt(data.compare_data.submission_id) : null
            };
          }
          if (data.version_message !== undefined) {
            self.versionMessage = String(data.version_message).trim();
          }
          if (data.version_verified !== undefined) {
            self.versionVerified = !!data.version_verified;
          }
          // 暫存且本次有上傳簽名檔時，後端已立即核實；顯示一致/不一致視窗，不必等刷新
          if (data.verified_this_request && data.compare_data) {
            skipDraftToast = true;
            const vr = data.compare_data.verify_result !== null && data.compare_data.verify_result !== undefined ? parseInt(data.compare_data.verify_result) : null;
            const note = data.compare_data.verify_note || (vr === 1 ? '核實結果一致' : vr === 2 ? '核實結果不一致' : '無法核實');
            self.versionVerified = (vr === 1);
            self.versionMessage = note;
            const safeMsg = String(note).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            if (vr === 1) {
              Swal.fire({
                icon: 'success',
                title: '',
                html: '<div style="font-size:28px;font-weight:bold;color:#16a34a;margin-bottom:8px;">一致</div><div style="font-size:16px;">' + safeMsg + '</div><div style="font-size:14px;color:#666;margin-top:8px;">表單已暫存</div>',
                confirmButtonText: '確定'
              });
            } else if (vr === 2) {
              Swal.fire({
                icon: 'warning',
                title: '',
                html: '<div style="font-size:28px;font-weight:bold;color:#dc2626;margin-bottom:8px;">不一致</div><div style="font-size:16px;font-weight:bold;color:#dc2626;">' + safeMsg + '</div><div style="font-size:14px;color:#666;margin-top:8px;">表單已暫存</div>',
                confirmButtonText: '確定'
              });
            } else {
              Swal.fire({
                icon: 'warning',
                title: '',
                html: '<div style="font-size:28px;font-weight:bold;color:#b45309;margin-bottom:8px;">無法核實</div><div style="font-size:16px;">' + safeMsg + '</div><div style="font-size:14px;color:#666;margin-top:8px;">表單已暫存</div>',
                confirmButtonText: '確定'
              });
            }
          }
          // 暫存僅處理內容與附件，不再在此觸發簽名比對
          if (data.question_image_paths && typeof data.question_image_paths === 'object') {
            Object.entries(data.question_image_paths).forEach(([order, path]) => {
              self.formAnswers['q_' + order] = path;
              const fileKey = 'q_' + order;
              if (self.questionImagePreview[fileKey]) {
                URL.revokeObjectURL(self.questionImagePreview[fileKey]);
                self.questionImagePreview[fileKey] = null;
              }
              self.questionImageFiles[fileKey] = null;
            });
          }
          if (self.supplementPdfFile && self.supplementPdfFileName) {
            self.draftAttachName = self.supplementPdfFileName;
            const pathname = window.location.pathname || '';
            self.draftAttachUrl = (pathname.includes('/pages/') ? '' : 'pages/') + 'download_document_form_draft_attachment.php?doc_ID=' + encodeURIComponent(self.selectedFileID);
          }
        } catch (e) {
          console.error('saveDraft API error', e);
          Swal.fire('錯誤', '無法連線，暫存僅存於本機', 'error');
          return;
        }
        try {
          const payload = {
            form_answers: this.formAnswers,
            apply_user: this.applyUser,
            apply_other: this.applyOther
          };
          // 僅在有有效 key（含使用者 ID）時才寫入本機暫存，避免共用電腦互相覆蓋
          if (key) {
            localStorage.setItem(key, JSON.stringify(payload));
          }
          // 「上次使用的表單」也改成與使用者綁定，避免不同學生互相影響
          const userId = window.CURRENT_USER && window.CURRENT_USER.u_ID
            ? String(window.CURRENT_USER.u_ID)
            : '';
          if (userId) {
            localStorage.setItem(
              'document_form_draft_last_file_id_u_' + userId,
              String(self.selectedFileID)
            );
          } else {
            // 保留舊 key 作為後備（舊資料仍可讀取）
            localStorage.setItem('document_form_draft_last_file_id', String(self.selectedFileID));
          }
          self.draftSaved = true;
          // 若已顯示一致/不一致視窗則不再跳出「已暫存」toast
          if (!skipDraftToast) {
            Swal.fire({
              icon: 'success',
              title: '已暫存',
              text: '表單內容已暫存，下次可繼續填寫（重新整理後仍會保留）',
              timer: 2200,
              showConfirmButton: false
            });
          }
          setTimeout(function () {
            self.draftSaved = false;
          }, 2500);
        } catch (e) {
          console.error('saveDraft localStorage error', e);
          Swal.fire('錯誤', '暫存失敗', 'error');
        }
      },
      // 簽名上傳：選擇簽名 PDF 後立即上傳並進行版本比對，學生無需再按「暫存」
      async uploadSignPdfForVerification() {
        if (!this.selectedFileID) {
          Swal.fire('提示', '請先選擇表單', 'info');
          return;
        }
        if (!this.signPdfFile) {
          return;
        }
        // 需要有已存在的 submission（sub_ID），才能對這一版文件進行簽名比對
        if (!this.draftSubId) {
          Swal.fire('提示', '請先按一次「暫存」產生草稿，再上傳簽名 PDF。', 'info');
          return;
        }

        const pathname = window.location.pathname || '';
        let base = '';
        if (pathname.includes('main.php')) {
          base = 'pages/';
        } else if (pathname.includes('/pages/')) {
          base = '';
        }

        // 優先使用簽名前 PDF 產生流程回傳的 sub_ID，其次退回暫存的 draftSubId
        let subIdForSign = null;
        if (typeof window !== 'undefined' && window.CURRENT_SUB_ID) {
          const v = Number(window.CURRENT_SUB_ID);
          if (Number.isFinite(v) && v > 0) subIdForSign = v;
        }
        if (!subIdForSign && this.draftSubId) {
          subIdForSign = this.draftSubId;
        }
        if (!subIdForSign || subIdForSign <= 0) {
          Swal.fire('錯誤', '請先按一次「暫存」，再產生簽名前 PDF。', 'error');
          return;
        }

        // 取得目前記憶體中的識別碼；若為空，嘗試向後端查詢 snapshot_token，一樣失敗才提示錯誤
        let snapToken = (typeof window !== 'undefined' && window.SNAPSHOT_TOKEN)
          ? String(window.SNAPSHOT_TOKEN)
          : '';

        if (!snapToken) {
          try {
            const tokenUrl = base + 'get_snapshot_token.php?sub_ID=' + encodeURIComponent(subIdForSign);
            const resTok = await fetch(tokenUrl, { credentials: 'same-origin' });
            if (resTok.ok) {
              const tokData = await resTok.json();
              if (tokData && tokData.ok && tokData.snapshot_token) {
                snapToken = String(tokData.snapshot_token);
                if (typeof window !== 'undefined') {
                  window.SNAPSHOT_TOKEN = snapToken;
                }
              }
            }
          } catch (e) {
            console.warn('get_snapshot_token 失敗:', e);
          }
        }

        if (!snapToken) {
          Swal.fire('錯誤', '請先產生簽名前版本，再上傳簽名 PDF。', 'error');
          return;
        }

        const formData = new FormData();
        formData.append('sub_ID', String(subIdForSign));
        formData.append('sign_pdf', this.signPdfFile, this.signPdfFileName || 'sign.pdf');

        try {
          const res = await fetch(base + 'verify_sign.php', {
            method: 'POST',
            body: formData
          });
          const data = await res.json();
          if (!data.ok) {
            this.versionVerified = false;
            this.versionMessage = data.message || data.msg || '簽名比對失敗';
            Swal.fire('錯誤', this.versionMessage, 'error');
            return;
          }

          const vr = typeof data.verify_result === 'number' ? data.verify_result : null;
          const note = data.verify_note || data.message || '';
          this.versionVerified = (vr === 1);
          this.versionMessage = note;

          // 一上傳簽名檔就立即提示核實結果（視窗顯示大大的「一致」或「不一致」）
          const rawMsg = note || (vr === 1
            ? '簽名 PDF 已上傳，內容與系統記錄一致。'
            : '簽名 PDF 內容與系統記錄不一致，請重新產生簽名前 PDF 後再簽名上傳。');
          const safeMsg = String(rawMsg)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

          if (vr === 1) {
            // 綠色粗體：一致
            Swal.fire({
              icon: 'success',
              title: '',
              html:
                '<div style="font-size:28px;font-weight:bold;color:#16a34a;margin-bottom:8px;">一致</div>' +
                '<div style="font-size:16px;">' + safeMsg + '</div>',
              confirmButtonText: '確定'
            });
          } else if (vr === 2) {
            // 紅色粗體：內容不一致
            Swal.fire({
              icon: 'warning',
              title: '',
              html:
                '<div style="font-size:28px;font-weight:bold;color:#dc2626;margin-bottom:8px;">不一致</div>' +
                '<div style="font-size:16px;font-weight:bold;color:#dc2626;">' + safeMsg + '</div>',
              confirmButtonText: '確定'
            });
          } else {
            // vr === 0 或 null：無法核實
            Swal.fire({
              icon: 'warning',
              title: '',
              html:
                '<div style="font-size:28px;font-weight:bold;color:#b45309;margin-bottom:8px;">無法核實</div>' +
                '<div style="font-size:16px;">' + safeMsg + '</div>',
              confirmButtonText: '確定'
            });
          }

          // 簽名上傳後重新載入核實資料，讓「核實結果」與版本時間與 DB 同步顯示
          this.loadCompareData().catch(() => { });
        } catch (e) {
          console.error('uploadSignPdfForVerification API error', e);
          Swal.fire('錯誤', '無法連線，簽名 PDF 上傳失敗', 'error');
        }
      },
      // 導出 PDF：無附件時開主 PDF；有附件時以 pdf-lib 合併主 PDF + 附件後下載
      async previewPdf() {
        if (!this.selectedForm) {
          Swal.fire('錯誤', '請先選擇表單', 'error');
          return;
        }
        const pathname = window.location.pathname || '';
        const base = pathname.includes('/pages/') ? '' : 'pages/';

        if (!this.draftSubId) {
          const pdfAction = base ? 'pages/document_form_pdf.php' : 'document_form_pdf.php';
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = pdfAction;
          form.target = '_blank';
          const fields = [
            { name: 'document_id', value: String(this.selectedFileID) },
            { name: 'form_answers', value: JSON.stringify(this.formAnswers) },
            { name: 'apply_user', value: this.applyUser || '' },
            { name: 'apply_other', value: this.applyOther || '' }
          ];
          fields.forEach(f => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = f.name;
            input.value = f.value;
            form.appendChild(input);
          });
          document.body.appendChild(form);
          form.submit();
          document.body.removeChild(form);
          return;
        }

        const exportMetaUrl = base + 'export_meta.php?sub_ID=' + encodeURIComponent(this.draftSubId);
        try {
          const res = await fetch(exportMetaUrl, { credentials: 'same-origin' });
          const data = await res.json();
          if (!data.ok) {
            Swal.fire('錯誤', data.msg || '取得預覽資訊失敗', 'error');
            return;
          }
          // 若後端能產生簽名前 PDF（含 SNAPSHOT_TOKEN）則用該份；否則改為開啟表單 PDF（snapshot_token 會在上傳簽名檔後由後端寫入）
          const pdfAction = base + 'document_form_pdf.php';
          const postFields = [
            { name: 'document_id', value: String(this.selectedFileID) },
            { name: 'submission_id', value: String(this.draftSubId) },
            { name: 'form_answers', value: JSON.stringify(this.formAnswers) },
            { name: 'apply_user', value: this.applyUser || '' },
            { name: 'apply_other', value: this.applyOther || '' }
          ];
          const genPdfUrl = base + 'generate_original_pdf.php';
          const genRes = await fetch(genPdfUrl, {
            method: 'POST',
            body: new URLSearchParams({ sub_ID: String(this.draftSubId), doc_ID: String(data.doc_ID) }),
            credentials: 'same-origin'
          });
          const genText = await genRes.text();
          let genData = null;
          try {
            genData = (genText && genText.trim()) ? JSON.parse(genText) : null;
          } catch (e) {
            console.warn('generate_original_pdf 回傳非 JSON', genText);
          }
          const useBackendPdf = (genData && genData.ok);
          if (!useBackendPdf) {
            // 後端尚未能產出簽名前 PDF（snapshot_token 為上傳簽名檔後才寫入）：改為開啟表單 PDF，預覽照常可用
            if (!data.attach_pdf_url) {
              const form = document.createElement('form');
              form.method = 'POST';
              form.action = pdfAction;
              form.target = '_blank';
              postFields.forEach(function (f) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = f.name;
                input.value = f.value;
                form.appendChild(input);
              });
              document.body.appendChild(form);
              form.submit();
              document.body.removeChild(form);
              Swal.fire({ icon: 'success', title: '已開啟表單 PDF', text: '請預覽、列印或另存後簽名，再上傳簽名檔即可', timer: 2000, showConfirmButton: false });
              return;
            }
            const attachUrl = (base ? base : '') + data.attach_pdf_url; // 目前主內容 PDF 已由 document_form_pdf.js 合併附件，這裡僅作備用資訊
            const iframe = document.createElement('iframe');
            iframe.name = 'pdf_export_iframe_' + Date.now();
            iframe.style.cssText = 'position:absolute;width:1px;height:1px;left:-9999px;';
            document.body.appendChild(iframe);
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = pdfAction;
            form.target = iframe.name;
            const postFieldsWithExport = postFields.concat([{ name: 'export', value: '1' }]);
            postFieldsWithExport.forEach(function (f) {
              const input = document.createElement('input');
              input.type = 'hidden';
              input.name = f.name;
              input.value = f.value;
              form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
            const fileName = data.filename || 'export.pdf';
            const handler = function (ev) {
              if (!ev.data || ev.data.type !== 'document_form_pdf_blob' || !ev.data.arrayBuffer) return;
              window.removeEventListener('message', handler);
              if (iframe.parentNode) document.body.removeChild(iframe);

              // 2026-03：document_form_pdf.js 內已統一負責合併補充附件與版本頁，
              // 這裡收到的是最終成品 PDF，避免再次合併同一份附件造成重複頁面。
              const mainBuf = ev.data.arrayBuffer;
              const blob = new Blob([mainBuf], { type: 'application/pdf' });
              const url = URL.createObjectURL(blob);
              const win = window.open('', '_blank');
              if (win) {
                win.location = url;
              }
              // 先不立即 revoke，避免新視窗尚未載入完成就失效，由瀏覽器在分頁關閉時釋放
              Swal.fire({
                icon: 'success',
                title: '已開啟 PDF 預覽',
                text: 'PDF 已包含補充附件與版本資訊',
                timer: 2000,
                showConfirmButton: false
              });
            };
            window.addEventListener('message', handler);
            setTimeout(function () {
              if (iframe.parentNode) { window.removeEventListener('message', handler); document.body.removeChild(iframe); }
            }, 60000);
            return;
          }
          const fileName = data.filename || 'export.pdf';
          const mainPdfUrl = base + 'download_document_form_original_pdf.php?doc_ID=' + encodeURIComponent(data.doc_ID) + '&submission_id=' + encodeURIComponent(this.draftSubId);
          if (!data.attach_pdf_url) {
            window.open(mainPdfUrl, '_blank');
            Swal.fire({ icon: 'success', title: '已開啟簽名前 PDF', text: '請列印或另存後簽名，再上傳即可通過版本驗證', timer: 2500, showConfirmButton: false });
            return;
          }
          const attachUrl = (base ? base : '') + data.attach_pdf_url;
          const mainBuf = await fetch(mainPdfUrl, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) return Promise.reject(new Error('簽名前 PDF 下載失敗'));
            return r.arrayBuffer();
          });
          const attachBuf = await fetch(attachUrl, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) return null;
            return r.arrayBuffer();
          });
          if (typeof PDFLib === 'undefined' || !PDFLib.PDFDocument) {
            const blob = new Blob([mainBuf], { type: 'application/pdf' });
            const url = URL.createObjectURL(blob);
            const win = window.open('', '_blank');
            if (win) {
              win.location = url;
            }
            Swal.fire({ icon: 'success', title: '已開啟 PDF 預覽', text: '簽名前 PDF（含 SNAPSHOT_TOKEN）', timer: 2000, showConfirmButton: false });
            return;
          }
          const mainPdf = await PDFLib.PDFDocument.load(mainBuf);
          if (attachBuf) {
            const attachPdf = await PDFLib.PDFDocument.load(attachBuf);
            const indices = attachPdf.getPageIndices();
            for (let i = 0; i < indices.length; i++) {
              const [copied] = await mainPdf.copyPages(attachPdf, [indices[i]]);
              if (copied) mainPdf.addPage(copied);
            }
          }
          const mergedBytes = await mainPdf.save();
          const blob = new Blob([mergedBytes], { type: 'application/pdf' });
          const url = URL.createObjectURL(blob);
          const win = window.open('', '_blank');
          if (win) {
            win.location = url;
          }
          Swal.fire({ icon: 'success', title: '已開啟 PDF 預覽', text: '簽名前 PDF（含 SNAPSHOT_TOKEN）與補充附件已合併', timer: 2500, showConfirmButton: false });
        } catch (e) {
          console.error(e);
          Swal.fire('錯誤', e.message || '預覽 PDF 失敗', 'error');
        }
      },
      async submitForm() {
        if (!this.selectedFile) {
          Swal.fire('錯誤', '請先選擇表單', 'error');
          return;
        }
        if (this.isSubmitted) {
          Swal.fire('錯誤', '此表單已提交，無法再次修改', 'error');
          return;
        }
        // 驗證必填欄位（排除審查用欄位），與 canSubmit 邏輯一致
        for (const q of this.fillableQuestions) {
          if (this.isReviewField(q)) continue;
          if (q.required && !this.isQuestionFilled(q)) {
            Swal.fire('錯誤', `請填寫「${q.title}」`, 'error');
            return;
          }
        }
        // 直接送出，不再經由核實對比彈窗
        this.confirmSubmit();
      },
      async loadCompareData() {
        this.compareDataLoading = true;
        try {
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          }

          console.log('開始載入對比資料，document_id:', this.selectedFileID);
          const res = await fetch(`${API_ROOT}?do=get_document_form_draft&action=compare&document_id=${this.selectedFileID}`);
          if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
          }
          const text = await res.text();
          let data = null;
          try {
            data = (text && text.trim()) ? JSON.parse(text) : null;
          } catch (parseErr) {
            console.warn('載入對比資料：後端回傳非 JSON，內容如下：', text.substring(0, 300));
            throw parseErr;
          }

          console.log('API返回數據:', data);

          if (data.ok && data.compare_data) {
            this.compareData = {
              originalPdfPath: data.compare_data.original_pdf_path || null,
              signPath: data.compare_data.sign_path || null,
              qrModifiedAt: data.compare_data.qr_modified_at || null,
              systemLastModified: data.compare_data.system_last_modified || null,
              verifyResult: data.compare_data.verify_result !== null && data.compare_data.verify_result !== undefined ? parseInt(data.compare_data.verify_result) : null,
              verifyNote: data.compare_data.verify_note || null,
              submissionId: data.compare_data.submission_id != null ? parseInt(data.compare_data.submission_id) : null
            };
            console.log('對比資料已載入:', this.compareData);
            console.log('signPath:', this.compareData.signPath);
            console.log('originalPdfPath:', this.compareData.originalPdfPath);
            console.log('verifyResult:', this.compareData.verifyResult);
          } else {
            console.warn('載入對比資料失敗或無數據:', data);
            // 即使API返回成功但沒有數據，也初始化為空（不影響 iframe 顯示）
            this.compareData = {
              originalPdfPath: null,
              signPath: null,
              qrModifiedAt: null,
              systemLastModified: null,
              verifyResult: null,
              verifyNote: null,
              submissionId: null
            };
          }
        } catch (e) {
          console.warn('載入對比資料錯誤（不影響 PDF 顯示）:', e.message || e);
          // 即使出錯也初始化為空，但不影響 iframe 顯示（因為我們已經改用 selectedFileID）
          this.compareData = {
            originalPdfPath: null,
            signPath: null,
            qrModifiedAt: null,
            systemLastModified: null,
            verifyResult: null,
            verifyNote: null,
            submissionId: null
          };
        } finally {
          this.compareDataLoading = false;
        }
      },
      getPdfUrl(path) {
        if (!path) return '';
        const pathname = window.location.pathname || '';
        // 如果path已經是完整URL，直接返回
        if (path.startsWith('http://') || path.startsWith('https://')) {
          return path;
        }
        // 如果path已經包含uploads/，直接使用
        if (path.startsWith('uploads/')) {
          const basePath = pathname.includes('/pages/') ? '../' : '';
          return basePath + path;
        }
        // 否則添加基礎路徑
        const basePath = pathname.includes('/pages/') ? '../' : '';
        return basePath + path;
      },
      async confirmSubmit() {
        // 2026-03：不再根據 QR 自動核實，只依三軌文字人工確認，這裡不再因「不一致」擋提交
        // 關閉對比視窗
        this.showCompareModal = false;

        try {
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          }

          const formData = new FormData();
          formData.append('document_id', this.selectedFileID);
          formData.append('apply_user', this.applyUser);
          formData.append('apply_other', this.applyOther);
          formData.append('form_answers', JSON.stringify(this.formAnswers));
          if (this.supplementPdfFile) {
            formData.append('supplement_pdf', this.supplementPdfFile, this.supplementPdfFileName || 'supplement.pdf');
          }

          const res = await fetch(`${API_ROOT}?do=submit_document_form`, {
            method: 'POST',
            body: formData
          });

          const data = await res.json();

          if (data.ok) {
            const key = this.getDraftKey();
            if (key) {
              try {
                localStorage.removeItem(key);
              } catch (e) { }
            }
            // 通知其他分頁（如 submission_view）重新載入目前文件的繳交狀態
            try {
              localStorage.setItem('submission_view_reload', JSON.stringify({
                doc_ID: this.selectedFileID,
                ts: Date.now()
              }));
            } catch (e) { }

            // 一份表單只能提交一次：設為已提交並顯示「本表單已提交」橫幅，表單變唯讀
            this.isSubmitted = true;
            this.submittedAt = data.submitted_at ? this.formatDateTime(data.submitted_at) : this.formatDateTime(new Date().toISOString());
            this.$nextTick(() => this.$forceUpdate()); // 強制更新視圖，確保所有 :disabled="isSubmitted" 立即生效

            Swal.fire({
              icon: 'success',
              title: '提交成功',
              text: data.message || '您的申請已成功提交',
              confirmButtonText: '確定'
            });
          } else {
            Swal.fire('錯誤', data.msg || '提交失敗', 'error');
          }
        } catch (e) {
          console.error('提交錯誤:', e);
          Swal.fire('錯誤', '無法連線到伺服器', 'error');
        }
      },
      // 取得科辦開放的表單列表（依目標設定過濾：學級、類組、班級、屆別），用於 apply_test 頁面
      async fetchFiles() {
        try {
          this.loading = true;
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          }
          const res = await fetch(`${API_ROOT}?do=get_available_document_forms`, { cache: 'no-store' });

          if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
          }

          const data = await res.json();

          if (data.ok && Array.isArray(data.forms)) {
            this.files = data.forms;
            // 還原上次暫存時選擇的表單，刷新頁面後表單與暫存內容會一併還原
            try {
              const userId = window.CURRENT_USER && window.CURRENT_USER.u_ID
                ? String(window.CURRENT_USER.u_ID)
                : '';
              let lastFileId = null;
              if (userId) {
                // 先讀取與使用者綁定的新 key，若沒有再回退舊 key（向後相容）
                lastFileId = localStorage.getItem('document_form_draft_last_file_id_u_' + userId)
                  || localStorage.getItem('document_form_draft_last_file_id');
              } else {
                lastFileId = localStorage.getItem('document_form_draft_last_file_id');
              }
              if (lastFileId && data.forms.some(function (f) { return String(f.doc_ID) === String(lastFileId); })) {
                this.selectedFileID = lastFileId;
              }
            } catch (e) { }
            // 防呆提示：如果沒有可用的表單，顯示提示訊息
            if (data.forms.length === 0) {
              Swal.fire({
                icon: 'info',
                title: '目前沒有可用的表單',
                html: '目前沒有符合您條件的表單可以填寫。<br><br>可能的原因：<br>• 表單尚未開放<br>• 表單已過期<br>• 表單的目標設定對象與您不符<br>• 您尚未加入專題團隊（某些表單需要）',
                confirmButtonText: '確定',
                confirmButtonColor: '#667eea'
              });
            }
          } else {
            this.files = [];
            if (data.msg) {
              Swal.fire({
                icon: 'warning',
                title: '載入表單失敗',
                text: data.msg,
                confirmButtonText: '確定',
                confirmButtonColor: '#667eea'
              });
            }
          }
        } catch (e) {
          console.error('fetchFiles error:', e);
          Swal.fire({
            icon: 'error',
            title: '無法載入表單列表',
            text: e.message || '請檢查網路連線或稍後再試',
            confirmButtonText: '確定',
            confirmButtonColor: '#667eea'
          });
        } finally {
          this.loading = false;
        }
      }
    },
    mounted() {
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
      if (userName) {
        this.applyUser = userName;
      }

      this.fetchFiles();

      // 加载 flatpickr（如果还没有加载）
      if (typeof flatpickr === 'undefined') {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css';
        document.head.appendChild(link);

        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js';
        script.onload = () => {
          const scriptZh = document.createElement('script');
          scriptZh.src = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh-tw.js';
          document.head.appendChild(scriptZh);
        };
        document.head.appendChild(script);
      }
    }
  });

  app.mount(mountSelector || '#app');
};
