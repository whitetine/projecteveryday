// 初始化防呆：避免頁面切換時重複執行
(function () {
    if (window.__formManageInited) {
        window.__formManageNeedReload = false;
        if (typeof window.loadTargetOptions === 'function') {
            setTimeout(function () { window.loadTargetOptions(); }, 0);
        }
        return;
    }
    window.__formManageInited = true;

    // 使用 window 對象存儲變數，避免重複宣告
    if (!window.__formManageData) {
        window.__formManageData = {
            questions: [],
            questionCounter: 0,
            saving: false,
            dbLookupMode: null,
            dbLookupPayload: null,
            dbLookupTeachers: [],
            dbLookupCohorts: [],
            searchStudentsTimer: null,
            gradesList: [],
            groupsList: [],
            classesList: []
        };
    }

    // 載入表單列表
    async function loadForms() {
        const tbody = document.getElementById('formsTableBody');
        if (!tbody) {
            // 如果元素還不存在，稍後重試
            setTimeout(loadForms, 50);
            return;
        }

        // 不顯示 loading，直接載入內容
        // 保持現有內容（如果有），避免閃爍
        let timeoutId = null;
        try {
            // 設置較短的超時，快速響應
            const controller = new AbortController();
            timeoutId = setTimeout(() => controller.abort(), 5000); // 5秒超時

            const response = await fetch('api.php?do=get_document_forms', {
                signal: controller.signal,
                cache: 'no-cache' // 確保獲取最新數據
            });

            if (timeoutId) {
                clearTimeout(timeoutId);
                timeoutId = null;
            }

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.ok) {
                // 使用文檔片段優化，一次性更新避免閃爍
                renderForms(data.forms || []);
            } else {
                // 顯示錯誤但也要更新 UI
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fa-solid fa-exclamation-triangle"></i>
                            <p>載入失敗：${escapeHtml(data.msg || '未知錯誤')}</p>
                            <button class="btn-action btn-edit" onclick="loadForms()" style="margin-top: 1rem;">重新載入</button>
                        </td>
                    </tr>
                `;
                showError('載入表單列表失敗：' + (data.msg || '未知錯誤'));
            }
        } catch (error) {
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
            console.error('載入表單列表錯誤:', error);

            // 確保 UI 更新，顯示錯誤狀態
            if (error.name === 'AbortError') {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fa-solid fa-clock"></i>
                            <p>載入超時，請稍後再試</p>
                            <button class="btn-action btn-edit" onclick="loadForms()" style="margin-top: 1rem;">重新載入</button>
                        </td>
                    </tr>
                `;
            } else {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fa-solid fa-exclamation-triangle"></i>
                            <p>載入失敗：${escapeHtml(error.message || '網路錯誤')}</p>
                            <button class="btn-action btn-edit" onclick="loadForms()" style="margin-top: 1rem;">重新載入</button>
                        </td>
                    </tr>
                `;
            }
            showError('載入表單列表失敗');
        }
    }

    // 渲染表單列表
    function renderForms(forms) {
        const tbody = document.getElementById('formsTableBody');
        if (!tbody) return;

        // 先準備完整的 HTML 字符串，然後一次性更新（原子操作，不會閃爍）
        let html = '';

        if (forms.length === 0) {
            html = `
                <tr>
                    <td colspan="6" class="empty-state">
                        <i class="fa-solid fa-inbox"></i>
                        <p>目前沒有表單</p>
                    </td>
                </tr>
            `;
        } else {
            html = forms.map(form => {
                const statusBadge = (form.doc_status != null ? form.doc_status : form.document_status) == 1
                    ? '<span class="badge badge-active">啟用</span>'
                    : '<span class="badge badge-inactive">停用</span>';

                const openTime = (form.doc_start_d || form.open_datetime)
                    ? new Date(form.doc_start_d || form.open_datetime).toLocaleString('zh-TW', {
                        year: 'numeric', month: '2-digit', day: '2-digit',
                        hour: '2-digit', minute: '2-digit'
                    })
                    : '-';

                const closeTime = (form.doc_end_d || form.close_datetime)
                    ? new Date(form.doc_end_d || form.close_datetime).toLocaleString('zh-TW', {
                        year: 'numeric', month: '2-digit', day: '2-digit',
                        hour: '2-digit', minute: '2-digit'
                    })
                    : '-';

                const docId = form.doc_ID != null ? form.doc_ID : form.document_id;
                const docName = form.doc_name || form.document_name || '';
                const docDes = form.doc_des || form.document_category || '';
                const docStatus = form.doc_status != null ? form.doc_status : form.document_status;

                // 顯示目標設定對象
                const targetDisplay = form.target_display || '未設定';
                const targetBadge = form.doc_target_all
                    ? '<span class="badge" style="background: #d1ecf1; color: #0c5460; padding: 0.35rem 0.75rem;">所有人</span>'
                    : '<span class="badge" style="background: #fff3cd; color: #856404; font-size: 0.85rem; padding: 0.35rem 0.75rem; white-space: normal; word-wrap: break-word; max-width: 300px; display: inline-block; line-height: 1.4;">' + escapeHtml(targetDisplay) + '</span>';

                return `
                    <tr>
                        <td>${escapeHtml(docName)}</td>
                        <td>${escapeHtml(docDes)}</td>
                        <td class="col-target">${targetBadge}</td>
                        <td class="col-datetime">
                            <div>${openTime}</div>
                            <div style="color: #6c757d; font-size: 0.85rem;">${closeTime}</div>
                        </td>
                        <td>${statusBadge}</td>
                        <td class="col-actions">
                            <div class="action-buttons">
                                <button class="btn-action btn-edit" onclick="editForm(${docId})">
                                    <i class="fa-solid fa-edit"></i> 編輯
                                </button>
                                <button class="btn-action btn-toggle" onclick="duplicateForm(${docId})" title="複製一份新表單再編輯">
                                    <i class="fa-solid fa-copy"></i> 複製
                                </button>
                                <a href="pages/document_form_pdf.php?document_id=${docId}" target="_blank" rel="noopener" class="btn-export-link btn-pdf" title="預覽並下載 PDF">
                                    <i class="fa-solid fa-file-pdf"></i> PDF
                                </a>
                                <span class="btn-group-inline">
                                    <button class="btn-action btn-toggle" onclick="toggleStatus(${docId}, ${docStatus})">
                                        <i class="fa-solid fa-${docStatus == 1 ? 'ban' : 'check'}"></i> ${docStatus == 1 ? '停用' : '啟用'}
                                    </button>
                                    <button class="btn-action btn-delete" onclick="deleteForm(${docId})" title="刪除此表單（無法復原）">
                                        <i class="fa-solid fa-trash-can"></i> 刪除
                                    </button>
                                </span>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // 直接更新，不等待動畫幀（更快響應）
        tbody.innerHTML = html;
    }

    // 編輯表單
    async function editForm(docId) {
        try {
            var root = document.getElementById('content') || document;
            var gradesEl = root.querySelector('#doc_target_grades');
            if (!gradesEl || !gradesEl.options.length) {
                await loadTargetOptions();
            }

            const response = await fetch(`api.php?do=get_document_form_detail&document_id=${docId}`);
            const data = await response.json();

            if (data.ok && data.form) {
                const form = data.form;
                let schema = form.form_schema;

                // 如果是字串，先轉成物件
                if (typeof schema === 'string') {
                    try {
                        schema = JSON.parse(schema);
                    } catch (e) {
                        schema = {};
                    }
                }

                // 如果不是物件也不是陣列，就給空物件
                if (!schema || typeof schema !== 'object') {
                    schema = {};
                }

                const formEl = document.getElementById('formForm');
                if (!formEl) {
                    showError('找不到表單容器 formForm');
                    return;
                }

                const doc_ID = form.doc_ID != null ? form.doc_ID : form.document_id;
                const doc_name = form.doc_name || form.document_name || '';
                const doc_header = form.doc_header || '';
                const doc_start_d = form.doc_start_d || form.open_datetime;
                const doc_end_d = form.doc_end_d || form.close_datetime;

                const documentIdEl = formEl.querySelector('#document_id');
                const documentNameEl = formEl.querySelector('#document_name');
                const docHeaderEl = formEl.querySelector('#doc_header');
                const isRequiredEl = formEl.querySelector('#is_required');
                const openDatetimeEl = formEl.querySelector('#open_datetime');
                const closeDatetimeEl = formEl.querySelector('#close_datetime');

                if (documentIdEl) documentIdEl.value = doc_ID;
                if (documentNameEl) documentNameEl.value = doc_name;
                if (docHeaderEl) docHeaderEl.value = doc_header;
                if (isRequiredEl) isRequiredEl.value = form.is_required == 1 ? '1' : '0';

                if (openDatetimeEl) {
                    openDatetimeEl.value = doc_start_d ? formatDateTimeLocal(new Date(doc_start_d)) : '';
                }

                if (closeDatetimeEl) {
                    closeDatetimeEl.value = doc_end_d ? formatDateTimeLocal(new Date(doc_end_d)) : '';
                }

                // 載入目標設定
                const targetGrades = form.doc_target_grades || [];
                const gradesSelect = formEl.querySelector('#doc_target_grades');
                if (gradesSelect) {
                    Array.from(gradesSelect.options).forEach(option => {
                        option.selected = targetGrades.includes(option.value);
                    });
                }

                const targetGroups = form.doc_target_groups || [];
                const groupsSelect = formEl.querySelector('#doc_target_groups');
                if (groupsSelect) {
                    Array.from(groupsSelect.options).forEach(option => {
                        option.selected = targetGroups.includes(option.value);
                    });
                }

                const targetClasses = form.doc_target_classes || [];
                const classesSelect = formEl.querySelector('#doc_target_classes');
                if (classesSelect) {
                    Array.from(classesSelect.options).forEach(option => {
                        option.selected = targetClasses.includes(option.value);
                    });
                }

                // 載入題目
                if (Array.isArray(schema)) {
                    window.__formManageData.questions = schema;
                } else if (schema && Array.isArray(schema.questions)) {
                    window.__formManageData.questions = schema.questions;
                } else {
                    window.__formManageData.questions = [];
                }

                const pdfFooterEl = formEl.querySelector('#pdf_footer_timestamps');
                if (pdfFooterEl) {
                    const v = form.pdf_footer_timestamps ?? schema.pdf_footer_timestamps;
                    const checked = (v === undefined || v === 1 || v === '1' || v === true || v === 'true');
                    pdfFooterEl.checked = checked;
                    if (checked) {
                        pdfFooterEl.setAttribute('checked', 'checked');
                    } else {
                        pdfFooterEl.removeAttribute('checked');
                    }
                }

                const supplementEnabledEl = formEl.querySelector('#supplement_enabled');
                const supplementNoteEl = formEl.querySelector('#supplement_note');
                const exresultEl = formEl.querySelector('#form_exresultdata');

                if (supplementEnabledEl) {
                    const se = form.supplement_attachment_enabled ?? schema.supplement_attachment_enabled;
                    const checked = (se === undefined || se === 1 || se === '1' || se === true || se === 'true');
                    supplementEnabledEl.checked = checked;
                    if (checked) {
                        supplementEnabledEl.setAttribute('checked', 'checked');
                    } else {
                        supplementEnabledEl.removeAttribute('checked');
                    }
                }

                if (supplementNoteEl) {
                    const sn = form.supplement_attachment_note ?? schema.supplement_attachment_note ?? '';
                    supplementNoteEl.value = sn;
                }

                if (exresultEl) {
                    
                    const ex = form.exresultdata ?? schema.exresultdata ?? 0;
                    const checked = Number(ex) === 1 || ex === true || ex === 'true';

                    exresultEl.checked = checked;
                    exresultEl.defaultChecked = checked;

                    console.log('exresultdata 原始值:', ex);
                    console.log(form, checked); 
                }

                console.log('載入時原始 questions:', window.__formManageData.questions.map(q =>
                    q.type === 'textarea' ? { title: q.title, type: q.type, rows: q.rows, rowsType: typeof q.rows } : null
                ).filter(Boolean));

                // 確保舊資料有新增的欄位
                window.__formManageData.questions.forEach(q => {
                    if (q.type === 'textarea') {
                        if (q.rows !== undefined && q.rows !== null) {
                            q.rows = parseInt(q.rows);
                            if (isNaN(q.rows) || q.rows < 1) q.rows = 5;
                        } else {
                            q.rows = 5;
                        }
                        if (q.textarea_display !== 'normal' && q.textarea_display !== 'large') {
                            q.textarea_display = 'normal';
                        }
                    }

                    if (q.type === 'students_advisor') {
                        if (!Array.isArray(q.students)) q.students = [{ student_id: '', name: '' }];
                        if (q.advisor === undefined) q.advisor = '';
                        if (!q.prefill_source) q.prefill_source = 'by_project';
                        if (q.allow_student_edit === undefined) q.allow_student_edit = true;
                        if (!q.advisor_field_type) q.advisor_field_type = 'single';
                    } else if (q.type === 'project_basic_block' || q.special_field === 'project_basic') {
                        q.special_field = 'project_basic';
                        if (!q.title) q.title = '專題基本資料';
                    } else if (q.type === 'sub_questions') {
                        if (!Array.isArray(q.subs)) q.subs = [];
                        const validTypes = ['text', 'textarea'];
                        q.subs = q.subs.map((s) => {
                            const rawType = s.type != null ? String(s.type).trim().toLowerCase() : '';
                            const type = validTypes.includes(rawType) ? rawType : 'text';
                            return {
                                label: s.label != null ? s.label : '',
                                type: type,
                                rows: typeof s.rows === 'number' && s.rows >= 1 ? s.rows : 1
                            };
                        });
                        if (q.sub_text === undefined || q.sub_text === null) {
                            q.sub_text = q.subs.map(s => (s.label || '').trim()).join('\n');
                        }
                    } else if (q.type === 'numbered_textarea') {
                        if (q.rows === undefined || q.rows === null) q.rows = 6;
                    } else if (q.type === 'file_upload' || q.type === 'attachment' || q.type === 'pdf_word') {
                        if (q.allowed_formats === undefined || q.allowed_formats === null) q.allowed_formats = 'pdf,doc,docx';
                    } else {
                        if (!q.special_field) q.special_field = 'none';
                        if (!q.prefill_source) q.prefill_source = 'none';
                        if (!Array.isArray(q.prefill_fields)) q.prefill_fields = [];
                    }
                });

                window.__formManageData.questionCounter = window.__formManageData.questions.length > 0
                    ? Math.max(...window.__formManageData.questions.map(q => q.order || 0)) + 1
                    : 0;

                renderQuestions();

                // 載入附件
                const docIdForAtt = formEl.querySelector('#document_id')?.value;
                if (docIdForAtt && parseInt(docIdForAtt) > 0) {
                    fetch('api.php?do=get_document_form_attachment&doc_ID=' + docIdForAtt)
                        .then(r => r.json())
                        .then(data => {
                            const el = document.getElementById('doc_attachment_current');
                            if (el) {
                                if (data.ok && data.attachment) {
                                    el.textContent = '目前附件：' + (data.attachment.display_name || '附件.pdf');
                                    const attachmentNameEl = document.getElementById('doc_attachment_name');
                                    if (attachmentNameEl) {
                                        attachmentNameEl.value = data.attachment.display_name || '';
                                    }
                                } else {
                                    el.textContent = '尚無附件';
                                }
                            }
                        })
                        .catch(() => { });
                } else {
                    const el = document.getElementById('doc_attachment_current');
                    if (el) el.textContent = '儲存表單後可上傳附件';
                }

                document.getElementById('modalTitle').textContent = '編輯表單';
                document.getElementById('formModal').classList.add('show');
                document.body.classList.add('modal-open');

                saveInitialSnapshot();
            } else {
                showError('載入表單詳情失敗：' + (data.msg || '未知錯誤'));
            }
        } catch (error) {
            console.error('載入表單詳情錯誤:', error);
            showError('載入表單詳情失敗');
        }
    }

    // 新增表單
    async function addForm() {
        // 檢查是否有未儲存的變更
        const formModal = document.getElementById('formModal');
        if (formModal && formModal.classList.contains('show') && hasUnsavedChanges()) {
            // 如果有未儲存變更，先詢問用戶
            if (window.Swal) {
                const result = await Swal.fire({
                    icon: 'warning',
                    title: '確認切換',
                    text: '您有未儲存的變更，確定要開啟新表單嗎？',
                    showCancelButton: true,
                    confirmButtonText: '開啟新表單',
                    cancelButtonText: '取消',
                    confirmButtonColor: '#dc3545',
                    reverseButtons: true
                });
                if (!result.isConfirmed) {
                    return;
                }
            } else {
                if (!confirm('您有未儲存的變更，確定要開啟新表單嗎？')) {
                    return;
                }
            }
        }

        // 確保目標設定選項已載入
        if (window.__formManageData.gradesList.length === 0) {
            await loadTargetOptions();
        }

        document.getElementById('formForm').reset();
        document.getElementById('document_id').value = '0';
        syncExresultCheckbox({ exresultdata: false });
        window.__formManageData.questions = [];
        window.__formManageData.questionCounter = 0;
        updateTargetSettingsVisibility();
        // 清空目標設定選擇（儲存時會驗證學級、類組各至少選一項）
        const gradesSelect = document.getElementById('doc_target_grades');
        const groupsSelect = document.getElementById('doc_target_groups');
        if (gradesSelect) gradesSelect.selectedIndex = -1;
        if (groupsSelect) groupsSelect.selectedIndex = -1;
        renderQuestions();
        document.getElementById('modalTitle').textContent = '新增表單';
        document.getElementById('formModal').classList.add('show');
        document.body.classList.add('modal-open');
        saveInitialSnapshot();

        // 清除自動儲存（因為這是新表單）
        clearAutoSavedFormData();
    }

    // 更新目標設定區塊的可見性
    function updateTargetSettingsVisibility() {
        // 目標設定改為一併選：學級、班級、類組永遠顯示，不再依「開放給所有人」隱藏
    }

    // 儲存表單（防重複送出：同一時間只允許一次儲存請求）
    async function saveForm() {
        if (window.__formManageData.saving) {
            return;
        }
        const form = document.getElementById('formForm');

        // 1. 基礎驗證（HTML5 required 等）
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        // 2. 額外強制驗證：文件名稱（避免純空白）
        const docName = document.getElementById('document_name').value.trim();
        if (!docName) {
            if (window.Swal) {
                Swal.fire({ icon: 'warning', title: '文件名稱未填寫', text: '請輸入表單的文件名稱。' });
            } else {
                alert('請輸入表單的文件名稱。');
            }
            return;
        }

        // 3. 驗證每個自定義欄位是否有標題
        const questions = window.__formManageData.questions;
        for (let i = 0; i < questions.length; i++) {
            const q = questions[i];
            // 專題基本資料區塊不需要自訂標題
            if (q.type === 'project_basic_block' || q.special_field === 'project_basic') continue;

            if (!q.title || q.title.trim() === '') {
                const msg = `第 ${i + 1} 個欄位的「欄位文字（標題）」為必填，請檢查。`;
                if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: '欄位標題未填寫', text: msg });
                } else {
                    alert(msg);
                }
                // 滾動到有問題的項次
                const item = document.querySelector(`.question-item[data-index="${i}"]`);
                if (item) item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
        }

        window.__formManageData.saving = true;
        const btnSave = document.getElementById('btnSaveForm');
        if (btnSave) {
            btnSave.disabled = true;
            btnSave.textContent = '儲存中...';
        }

        const remark = '';

        // 確保所有題目的 rows 值正確保存（特別是 textarea 類型）
        window.__formManageData.questions.forEach(q => {
            if (q.type === 'textarea' && q.rows !== undefined && q.rows !== null) {
                // 確保 rows 是數字類型
                q.rows = parseInt(q.rows) || 5;
            }
        });

        // 將備註、補充附件說明與 PDF 頁尾設定存儲在 form_schema 的 metadata 中（便利 > 複雜，進階不佔畫面）
        const pdfFooterTimestamps = document.getElementById('pdf_footer_timestamps');
        const pdfFooterChecked = pdfFooterTimestamps ? pdfFooterTimestamps.checked : true;
        const supplementEnabledEl = document.getElementById('supplement_enabled');
        const supplementNoteEl = document.getElementById('supplement_note');
        const supplementEnabled = supplementEnabledEl ? supplementEnabledEl.checked : true;
        const supplementNote = supplementNoteEl ? supplementNoteEl.value.trim() : '';

        const questionsToSave = window.__formManageData.questions.map(q => {
            if (q.type === 'sub_questions') {
                const raw = (q.sub_text !== undefined && q.sub_text !== null) ? String(q.sub_text) : (Array.isArray(q.subs) ? q.subs.map(s => (s.label || '').trim()).join('\n') : '');
                const subs = raw.split(/\r?\n/).map(line => line.trim()).filter(Boolean).map(label => ({ label: label, type: 'text', rows: 1 }));
                const { sub_text, ...rest } = q;
                return { ...rest, subs };
            }
            // 舊資料兼容：儲存時統一存成 file_upload
            if (q.type === 'attachment' || q.type === 'pdf_word') {
                const { type, ...rest } = q;
                return { ...rest, type: 'file_upload' };
            }
            return q;
        });
        let formSchemaToSave;
        const exresultChecked = document.getElementById('form_exresultdata')?.checked || false;

        formSchemaToSave = {
            _remark: remark || '',
            pdf_footer_timestamps: pdfFooterChecked ? 1 : 0,
            supplement_attachment_enabled: supplementEnabled ? 1 : 0,
            supplement_attachment_note: supplementNote || '',
            questions: questionsToSave,
            exresultdata: exresultChecked
        };

        // 調試：檢查 rows 值
        const textareaQuestions = window.__formManageData.questions.filter(q => q.type === 'textarea');
        if (textareaQuestions.length > 0) {
            console.log('儲存前檢查 rows 值:', textareaQuestions.map(q => ({ title: q.title, rows: q.rows, rowsType: typeof q.rows })));
        }

        // 收集目標設定（學級、類組皆為必填，至少各選一項）
        const gradesSelect = document.getElementById('doc_target_grades');
        const groupsSelect = document.getElementById('doc_target_groups');
        const targetGrades = gradesSelect ? Array.from(gradesSelect.selectedOptions).map(opt => opt.value) : [];
        const targetGroups = groupsSelect ? Array.from(groupsSelect.selectedOptions).map(opt => opt.value) : [];
        const targetClasses = []; // 班級目標設定已取消，後端以空陣列視為不限制班級

        if (targetGrades.length === 0 || targetGroups.length === 0) {
            window.__formManageData.saving = false;
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.textContent = '儲存';
            }
            const msg = '請在目標設定中，於「學級(屆別)」、「類組」各至少選一項。';
            if (window.Swal) {
                Swal.fire({ icon: 'warning', title: '目標設定未填寫完整', text: msg });
            } else {
                alert(msg);
            }
            return;
        }

        const targetAll = 0;

        const formData = {
            doc_ID: parseInt(document.getElementById('document_id').value) || 0,
            document_id: parseInt(document.getElementById('document_id').value) || 0,
            doc_name: document.getElementById('document_name').value.trim(),
            doc_header: (document.getElementById('doc_header')?.value ?? '').trim(),
            doc_des: '審查文件',
            is_required: document.getElementById('is_required').value === '1' ? 1 : 0,
            doc_start_d: document.getElementById('open_datetime').value || null,
            doc_end_d: document.getElementById('close_datetime').value || null,
            doc_status: 1,
            form_schema: formSchemaToSave,
            doc_target_all: targetAll,
            doc_target_grades: targetGrades,
            doc_target_groups: targetGroups,
            doc_target_classes: targetClasses
        };

        try {
            const response = await fetch('api.php?do=save_document_form', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();

            if (data.ok) {
                const savedDocId = data.doc_ID || data.document_id || document.getElementById('document_id').value;

                // 驗證表單是否正確同步（可選的驗證步驟）
                try {
                    const verifyRes = await fetch(`api.php?do=get_document_form_detail&document_id=${savedDocId}`);
                    const verifyData = await verifyRes.json();

                    if (!verifyData.ok || !verifyData.form) {
                        console.warn('表單儲存成功，但驗證時發現問題:', verifyData.msg);
                    } else {
                        // 驗證 form_schema 格式
                        const formSchema = verifyData.form.form_schema;
                        if (formSchema) {
                            const schema = typeof formSchema === 'string' ? JSON.parse(formSchema) : formSchema;
                            const questions = Array.isArray(schema) ? schema : (schema.questions || []);

                            if (!Array.isArray(questions)) {
                                console.warn('表單 schema 格式異常，可能影響學生端顯示');
                            }
                        }

                        // 驗證目標設定是否正確儲存
                        const hasTargets = verifyData.form.doc_target_all ||
                            (verifyData.form.doc_target_grades && verifyData.form.doc_target_grades.length > 0) ||
                            (verifyData.form.doc_target_groups && verifyData.form.doc_target_groups.length > 0) ||
                            (verifyData.form.doc_target_classes && verifyData.form.doc_target_classes.length > 0);

                        if (!hasTargets && !verifyData.form.doc_target_all) {
                            console.info('表單未設定目標對象，將顯示給所有學生');
                        }
                    }
                } catch (verifyError) {
                    console.warn('表單驗證過程發生錯誤（不影響儲存）:', verifyError);
                }

                // 儲存成功後，清空表單資料（因為已經儲存）
                document.getElementById('formForm').reset();
                document.getElementById('document_id').value = '0';
                window.__formManageData.questions = [];
                window.__formManageData.questionCounter = 0;

                // 清除自動儲存的資料（因為已經成功儲存）
                clearAutoSavedFormData();

                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: '成功',
                        text: data.message || '表單已儲存',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    alert(data.message || '表單已儲存');
                }
                closeModal();
                loadForms();
            } else {
                showError('儲存失敗：' + (data.msg || '未知錯誤'));
                // 儲存失敗時不關閉 modal，讓用戶可以修正後再試
                // 自動儲存的資料會保留，讓用戶可以繼續編輯
            }
        } catch (error) {
            console.error('儲存表單錯誤:', error);
            showError('儲存表單失敗');
        } finally {
            window.__formManageData.saving = false;
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.textContent = '儲存';
            }
        }
    }

    // 切換狀態（停用/啟用，不刪除資料）
    async function toggleStatus(docId, currentStatus) {
        try {
            const response = await fetch('api.php?do=toggle_document_form_status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ doc_ID: docId, document_id: docId })
            });

            const data = await response.json();

            if (data.ok) {
                loadForms();
            } else {
                showError('操作失敗：' + (data.msg || '未知錯誤'));
            }
        } catch (error) {
            console.error('切換狀態錯誤:', error);
            showError('操作失敗');
        }
    }

    // 刪除表單（從資料庫移除，無法復原；與停用不同）
    async function deleteForm(docId) {
        const msg = '確定要刪除此表單嗎？刪除後無法復原。';
        let confirmed = false;
        if (window.Swal) {
            const result = await Swal.fire({
                icon: 'warning',
                title: '刪除表單',
                text: msg,
                showCancelButton: true,
                confirmButtonText: '刪除',
                cancelButtonText: '取消',
                confirmButtonColor: '#dc3545',
                reverseButtons: true
            });
            confirmed = result.isConfirmed;
        } else {
            confirmed = confirm(msg);
        }
        if (!confirmed) return;

        try {
            const response = await fetch('api.php?do=delete_document_form', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ doc_ID: docId, document_id: docId })
            });
            const data = await response.json();

            if (data.ok) {
                if (window.Swal) {
                    Swal.fire({ icon: 'success', title: '已刪除', text: data.message || '表單已刪除', timer: 1500, showConfirmButton: false });
                } else {
                    alert(data.message || '表單已刪除');
                }
                loadForms();
            } else {
                showError('刪除失敗：' + (data.msg || '未知錯誤'));
            }
        } catch (error) {
            console.error('刪除表單錯誤:', error);
            showError('刪除表單失敗');
        }
    }

    // 複製表單：先在後端建立一筆「副本」資料，再開啟該筆供編輯
    async function duplicateForm(docId) {
        try {
            const confirmMsg = '將以這份表單的設定為基礎，先在系統中建立一份「副本」表單，再開啟供您編輯。要繼續嗎？';
            let confirmed = false;
            if (window.Swal) {
                const result = await Swal.fire({
                    icon: 'question',
                    title: '複製表單',
                    text: confirmMsg,
                    showCancelButton: true,
                    confirmButtonText: '建立副本',
                    cancelButtonText: '取消',
                    confirmButtonColor: '#0d6efd',
                    reverseButtons: true
                });
                confirmed = result.isConfirmed;
            } else {
                confirmed = confirm(confirmMsg);
            }
            if (!confirmed) return;

            // 讀取原始表單詳情
            const res = await fetch(`api.php?do=get_document_form_detail&document_id=${docId}`);
            const data = await res.json();
            if (!data.ok || !data.form) {
                showError('讀取原始表單失敗：' + (data.msg || '未知錯誤'));
                return;
            }
            const form = data.form;

            // 解析 schema
            let schema = form.form_schema;
            if (typeof schema === 'string') {
                try {
                    schema = JSON.parse(schema);
                } catch (e) {
                    schema = {};
                }
            }
            if (!schema || typeof schema !== 'object') {
                schema = {};
            }

            // 準備送到後端建立「副本」的 payload
            const baseName = form.doc_name || form.document_name || '';
            const newName = baseName ? `${baseName} - 副本` : '未命名表單 - 副本';
            const doc_start_d = form.doc_start_d || form.open_datetime;
            const doc_end_d = form.doc_end_d || form.close_datetime;
            const targetGrades = form.doc_target_grades || [];
            const targetGroups = form.doc_target_groups || [];
            const targetClasses = form.doc_target_classes || [];
            const targetAll = form.doc_target_all ? 1 : 0;

            // form_schema：直接沿用原本 schema（保持所有設定）
            const payload = {
                doc_ID: 0,
                document_id: 0,
                doc_name: newName,
                doc_header: form.doc_header || '',
                doc_des: '審查文件',
                is_required: form.is_required == 1 ? 1 : 0,
                doc_start_d: doc_start_d || null,
                doc_end_d: doc_end_d || null,
                doc_status: 0, // 副本一律先停用
                form_schema: schema,
                doc_target_all: targetAll,
                doc_target_grades: targetGrades,
                doc_target_groups: targetGroups,
                doc_target_classes: targetClasses
            };

            const saveRes = await fetch('api.php?do=save_document_form', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const saveData = await saveRes.json();
            if (!saveData.ok || !(saveData.doc_ID || saveData.document_id)) {
                showError('建立副本失敗：' + (saveData.msg || '未知錯誤'));
                return;
            }

            const newDocId = saveData.doc_ID || saveData.document_id;

            if (window.Swal) {
                await Swal.fire({
                    icon: 'success',
                    title: '已建立副本',
                    text: '新表單已建立，接下來會開啟副本供您編輯。',
                    timer: 1500,
                    showConfirmButton: false
                });
            }

            // 重新載入列表，並開啟新建立的副本供編輯
            await loadForms();
            editForm(newDocId);
        } catch (error) {
            console.error('duplicateForm error:', error);
            showError('複製表單失敗');
        }
    }

    // 題目管理
    function renderQuestions() {
        const container = document.getElementById('questionsContainer');
        if (!container) return;

        const questions = window.__formManageData.questions;

        if (questions.length === 0) {
            container.innerHTML = '<p style="color: #6c757d; text-align: center; padding: 1rem;">目前沒有欄位，請點擊「新增欄位」按鈕新增</p>';
            return;
        }

        questions.sort((a, b) => (a.order || 0) - (b.order || 0));

        container.innerHTML = questions.map((q, index) => {
            const isProjectBasic = q.type === 'project_basic_block' || q.special_field === 'project_basic';
            const isSA = q.type === 'students_advisor' && !isProjectBasic; // 舊版專題生+指導老師
            const students = Array.isArray(q.students) ? q.students : [{ student_id: '', name: '' }];
            const advisor = q.advisor || '';
            // 題型顯示：舊資料 type=attachment 或 pdf_word 兼容為 file_upload
            let qType = (q.type === 'project_basic_block' ? 'project_basic_block' : q.type) || 'text';
            if (qType === 'attachment' || qType === 'pdf_word') qType = 'file_upload';
            let qRows = 5;
            const textareaDisplay = (q.textarea_display === 'large' || q.textarea_display === 'normal') ? q.textarea_display : 'normal';
            if (qType === 'textarea' || qType === 'numbered_textarea') {
                if (q.rows !== undefined && q.rows !== null) {
                    const parsedRows = parseInt(q.rows);
                    if (!isNaN(parsedRows) && parsedRows >= 1) qRows = parsedRows;
                } else if (qType === 'numbered_textarea') {
                    qRows = 6;
                }
            }
            const prefillSource = q.prefill_source || 'none';
            const specialField = q.special_field || 'none';

            const deprecatedType = (qType === 'project_basic_block' || qType === 'students_advisor');
            const imageUploadDeprecated = (qType === 'image_upload');
            const fileUploadDeprecated = (qType === 'file_upload');
            const numberDeprecated = (qType === 'number');
            const allowedFormatsStr = (qType === 'file_upload' && (q.allowed_formats != null)) ? String(q.allowed_formats) : '';
            const allowedFormatsArr = allowedFormatsStr ? allowedFormatsStr.split(',').map(s => s.trim()).filter(Boolean) : [];
            const allowedImage = allowedFormatsArr.includes('image');
            const allowedPdf = allowedFormatsArr.includes('pdf');
            const allowedWord = allowedFormatsArr.includes('doc') || allowedFormatsArr.includes('docx');
            const typeSelect = `
                <div class="question-type-row">
                    <div>
                        <label>欄位類型</label>
                        <select onchange="onQuestionTypeChange(${index}, this.value)" class="q-type-select" ${imageUploadDeprecated || fileUploadDeprecated || numberDeprecated ? 'disabled' : ''}>
                            ${deprecatedType || fileUploadDeprecated || numberDeprecated ? '<option value="" disabled selected>（此題型已停用）</option>' : ''}
                            ${imageUploadDeprecated ? '<option value="image_upload" selected>圖檔上傳（學生上傳）— 此題型目前停用</option>' : ''}
                            ${fileUploadDeprecated ? '<option value="file_upload" selected>檔案上傳 — 此題型目前停用</option>' : ''}
                            ${numberDeprecated ? '<option value="number" selected>數字/評分 — 此題型目前停用</option>' : ''}
                            <option value="text" ${qType === 'text' ? 'selected' : ''}>文字（單行）</option>
                            <option value="textarea" ${qType === 'textarea' ? 'selected' : ''}>文字（多行）</option>
                            <option value="sub_questions" ${qType === 'sub_questions' ? 'selected' : ''}>子題多小題</option>
                            <option value="date" ${qType === 'date' ? 'selected' : ''}>日期</option>
                            <option value="table" ${qType === 'table' ? 'selected' : ''}>表格</option>
                        </select>
                    </div>
                    <div>
                        <label>特殊欄位</label>
                        <select onchange="onSpecialFieldChange(${index}, this.value)" class="q-special-select">
                            <option value="none" ${specialField === 'none' ? 'selected' : ''}>學生填寫</option>
                            <option value="office_remark" ${specialField === 'office_remark' ? 'selected' : ''}>科辦＆指導老師填寫</option>
                        </select>
                    </div>
                    ${specialField === 'auto_readonly' ? `
                    <div style="grid-column: 1 / -1;">
                        <label>自動帶入來源</label>
                        <select onchange="updateAutoReadonlySource(${index}, this.value)" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.9rem;">
                            <option value="project_title" ${(q.auto_readonly_source || q.prefill_source || 'project_title') === 'project_title' ? 'selected' : ''}>專題題目</option>
                            <option value="project_students" ${(q.auto_readonly_source || q.prefill_source) === 'project_students' ? 'selected' : ''}>專題生</option>
                            <option value="advisor" ${(q.auto_readonly_source || q.prefill_source) === 'advisor' ? 'selected' : ''}>指導老師</option>
                            <option value="cohort" ${(q.auto_readonly_source || q.prefill_source) === 'cohort' ? 'selected' : ''}>屆別</option>
                            <option value="group_id" ${(q.auto_readonly_source || q.prefill_source) === 'group_id' ? 'selected' : ''}>組別ID</option>
                        </select>
                    </div>
                    ` : ''}
                </div>`;

            // 專題基本資料區塊：只顯示標題與刪除，不顯示題型/特殊欄位
            if (isProjectBasic) {
                return `
                    <div class="question-item" data-index="${index}">
                        <div class="question-header">
                            <div class="question-order">
                                <button type="button" class="btn-order" onclick="moveQuestion(${index}, -1)" ${index === 0 ? 'disabled' : ''}><i class="fa-solid fa-arrow-up"></i></button>
                                <button type="button" class="btn-order" onclick="moveQuestion(${index}, 1)" ${index === questions.length - 1 ? 'disabled' : ''}><i class="fa-solid fa-arrow-down"></i></button>
                                <span style="font-weight: 500;">專題基本資料（系統自動帶入）</span>
                            </div>
                            <button type="button" class="btn-remove-question" onclick="removeQuestion(${index})"><i class="fa-solid fa-trash"></i> 刪除</button>
                        </div>
                        <div class="question-content">
                            <p style="margin: 0; color: #6c757d; font-size: 0.9rem;">學生端將固定顯示：專題題目、專題生清單、指導老師（唯讀）</p>
                        </div>
                    </div>`;
            }

            if (isSA) {
                // 專題生/指導老師題型的專用設定
                const saPrefillSource = q.prefill_source || 'by_project'; // 預設 by_project
                const saAllowEdit = q.allow_student_edit !== undefined ? q.allow_student_edit : true; // 預設 true
                const isAutoPrefill = saPrefillSource === 'by_project' || saPrefillSource === 'mixed';

                const studentsHtml = students.map((s, si) => `
                    <div class="student-row" data-q="${index}" data-si="${si}">
                        <input type="text" class="wide-input" placeholder="${isAutoPrefill ? '學生端將自動帶入' : '學號'}" 
                               value="${escapeHtml(s.student_id || '')}"
                               ${isAutoPrefill ? 'disabled' : ''}
                               onchange="updateStudentCell(${index}, ${si}, 'student_id', this.value)">
                        <input type="text" class="wide-input" placeholder="${isAutoPrefill ? '學生端將自動帶入' : '姓名'}" 
                               value="${escapeHtml(s.name || '')}"
                               ${isAutoPrefill ? 'disabled' : ''}
                               onchange="updateStudentCell(${index}, ${si}, 'name', this.value)">
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <button type="button" class="btn-remove-option" onclick="removeStudentRow(${index}, ${si})" title="刪除" ${isAutoPrefill ? 'style="display: none;"' : ''}>
                                <i class="fa-solid fa-times"></i>
                            </button>
                        </div>
                    </div>
                `).join('');

                return `
                    <div class="question-item" data-index="${index}">
                        <div class="question-header">
                            <div class="question-order">
                                <button type="button" class="btn-order" onclick="moveQuestion(${index}, -1)" ${index === 0 ? 'disabled' : ''}><i class="fa-solid fa-arrow-up"></i></button>
                                <button type="button" class="btn-order" onclick="moveQuestion(${index}, 1)" ${index === questions.length - 1 ? 'disabled' : ''}><i class="fa-solid fa-arrow-down"></i></button>
                                <span style="font-weight: 500;">欄位 ${index + 1}</span>
                            </div>
                            <button type="button" class="btn-remove-question" onclick="removeQuestion(${index})"><i class="fa-solid fa-trash"></i> 刪除</button>
                        </div>
                        <div class="question-content">
                            <input type="text" class="question-title-input" value="${escapeHtml(q.title || '')}" placeholder="請輸入欄位文字（必填）"
                                   required onchange="updateQuestionTitle(${index}, this.value)">
                            ${typeSelect}
                            <div class="advanced-settings-body always-open" data-qidx="${index}">
                                <div class="question-meta" style="margin-bottom: 0.75rem;">
                                    <div class="question-required-switch">
                                        <label class="switch-container">
                                            <span>必填</span>
                                            <label class="switch">
                                                <input type="checkbox" ${q.required ? 'checked' : ''} onchange="updateQuestionRequired(${index}, this.checked)">
                                                <span class="slider"></span>
                                            </label>
                                        </label>
                                    </div>
                                </div>
                                <div class="question-type-settings" style="padding: 0.75rem; background: #f8f9fa; border-radius: 4px;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                        <div>
                                            <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">專題生列表列數</label>
                                            <input type="number" min="1" max="10" value="${students.length}" onchange="updateSAStudentRows(${index}, this.value)" style="width: 100%; padding: 0.4rem 0.75rem; border: 1px solid #ced4da; border-radius: 4px;">
                                        </div>
                                        <div>
                                            <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">指導老師欄位</label>
                                            <select onchange="updateSAAdvisorField(${index}, this.value)" style="width: 100%; padding: 0.4rem 0.75rem; border: 1px solid #ced4da; border-radius: 4px;">
                                                <option value="single" ${q.advisor_field_type === 'single' ? 'selected' : ''}>單一欄位</option>
                                                <option value="signature" ${q.advisor_field_type === 'signature' ? 'selected' : ''}>簽名欄位</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                        <div>
                                            <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">資料來源</label>
                                            <select onchange="updateSAPrefillSource(${index}, this.value)" style="width: 100%; padding: 0.4rem 0.75rem; border: 1px solid #ced4da; border-radius: 4px;">
                                                <option value="none" ${saPrefillSource === 'none' ? 'selected' : ''}>不使用（全部手動填寫）</option>
                                                <option value="by_project" ${saPrefillSource === 'by_project' ? 'selected' : ''}>自動帶入（依專題/分組資料）</option>
                                                <option value="mixed" ${saPrefillSource === 'mixed' ? 'selected' : ''}>先自動帶入，但學生可新增/刪除</option>
                                            </select>
                                        </div>
                                        <div style="display: flex; align-items: flex-end;">
                                            <label style="font-weight: 500; font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                                <input type="checkbox" ${saAllowEdit ? 'checked' : ''} onchange="updateSAAllowStudentEdit(${index}, this.checked)" style="width: auto; margin: 0;">
                                                <span>學生是否可編輯</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="question-remark-row">
                                    <label class="remark-check">
                                        <input type="checkbox" ${q.remark_enabled ? 'checked' : ''} onchange="updateQuestionRemarkEnabled(${index}, this.checked)">
                                        <span>備註（科辦可填寫）</span>
                                    </label>
                                    ${q.remark_enabled ? `
                                        <textarea class="remark-textarea" placeholder="科辦備註內容" oninput="updateQuestionRemarkText(${index}, this.value)">${escapeHtml(q.remark_text || '')}</textarea>
                                    ` : ''}
                                </div>
                                <div class="students-advisor-block">
                                    <div class="students-advisor-label">專題生：</div>
                                    ${studentsHtml}
                                    <button type="button" class="btn-add-student" onclick="addStudentRow(${index})" ${isAutoPrefill ? 'style="display: none;"' : ''}><i class="fa-solid fa-plus"></i> 新增專題生</button>
                                    <div class="students-advisor-label" style="margin-top: 1rem;">指導老師：</div>
                                    <div class="advisor-row">
                                        <input type="text" class="wide-input" placeholder="${isAutoPrefill ? '學生端將自動帶入' : '可手動填寫'}" 
                                               value="${escapeHtml(advisor)}"
                                               ${isAutoPrefill ? 'disabled' : ''}
                                               onchange="updateAdvisor(${index}, this.value)">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }

            const optionsHtml = Array.isArray(q.options) && q.options.length > 0
                ? q.options.map((opt, optIndex) => `
                    <div class="option-item">
                        <input type="text" class="option-input" value="${escapeHtml(opt)}" placeholder="選項 ${optIndex + 1}"
                               onchange="updateQuestionOption(${index}, ${optIndex}, this.value)">
                        <button type="button" class="btn-remove-option" onclick="removeQuestionOption(${index}, ${optIndex})"><i class="fa-solid fa-times"></i></button>
                    </div>
                `).join('') : '';
            const showOptions = q.type === 'radio' || q.type === 'checkbox';
            const showPrefill = q.type !== 'image_upload' && q.type !== 'file_upload' && q.type !== 'number' && q.type !== 'sub_questions' && q.type !== 'numbered_textarea' && !isSA && specialField !== 'auto_readonly';

            return `
                <div class="question-item" data-index="${index}">
                    <div class="question-header">
                        <div class="question-order">
                            <button type="button" class="btn-order" onclick="moveQuestion(${index}, -1)" ${index === 0 ? 'disabled' : ''}><i class="fa-solid fa-arrow-up"></i></button>
                            <button type="button" class="btn-order" onclick="moveQuestion(${index}, 1)" ${index === questions.length - 1 ? 'disabled' : ''}><i class="fa-solid fa-arrow-down"></i></button>
                            <span style="font-weight: 500;">欄位 ${index + 1}</span>
                        </div>
                        <button type="button" class="btn-remove-question" onclick="removeQuestion(${index})"><i class="fa-solid fa-trash"></i> 刪除</button>
                    </div>
                    <div class="question-content">
                        <input type="text" class="question-title-input" value="${escapeHtml(q.title || '')}" placeholder="請輸入欄位文字（必填）"
                               required onchange="updateQuestionTitle(${index}, this.value)">
                        ${typeSelect}
                            ${qType === 'sub_questions' ? `
                        <div class="question-type-settings sub-questions-admin-wrap" style="margin-top: 0.75rem; margin-bottom: 0.75rem; padding: 0.75rem; background: #f8f9fa; border-radius: 4px;">
                            <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">小題清單</label>
                            <p style="font-size: 0.85rem; color: #6c757d; margin-bottom: 0.5rem;">每行輸入一小題，左側顯示 1. 2. 3. 編號，換行時自動顯示下一編號，匯出時為編號列表</p>
                            <div class="sub-questions-numbered-wrap" data-qidx="${index}">
                                <div class="sub-questions-line-numbers">1.</div>
                                <textarea class="form-control sub-questions-textarea" rows="8" placeholder="每行輸入一小題"
                                    data-qidx="${index}"
                                    oninput="updateSubText(${index}, this.value); updateSubTextLineNumbers(this);"
                                    onscroll="syncSubNumbersScroll(this)">${escapeHtml(q.sub_text !== undefined && q.sub_text !== null ? String(q.sub_text) : (Array.isArray(q.subs) ? q.subs.map(s => (s.label || '').trim()).join('\n') : ''))}</textarea>
                            </div>
                        </div>
                            ` : ''}
                        <div class="advanced-settings-body always-open" data-qidx="${index}">
                            <div class="question-meta" style="margin-bottom: 0.75rem;">
                                <div class="question-required-switch">
                                    <label class="switch-container">
                                        <span>必填</span>
                                        <label class="switch">
                                            <input type="checkbox" ${q.required ? 'checked' : ''} onchange="updateQuestionRequired(${index}, this.checked)">
                                            <span class="slider"></span>
                                        </label>
                                    </label>
                                </div>
                            </div>
                            ${(qType === 'textarea' || qType === 'numbered_textarea') ? `
                                <div style="margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-size: 0.9rem;">行數</span>
                                    <select onchange="updateQuestionRows(${index}, this.value)" style="padding: 0.35rem 0.6rem; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.9rem;">
                                        <option value="3" ${qRows === 3 ? 'selected' : ''}>3</option>
                                        <option value="5" ${qRows === 5 ? 'selected' : ''}>5</option>
                                        <option value="6" ${qRows === 6 ? 'selected' : ''}>6</option>
                                        <option value="8" ${qRows === 8 ? 'selected' : ''}>8</option>
                                        <option value="12" ${qRows === 12 ? 'selected' : ''}>12</option>
                                        <option value="15" ${qRows === 15 ? 'selected' : ''}>15</option>
                                        <option value="20" ${qRows === 20 ? 'selected' : ''}>20</option>
                                        <option value="25" ${qRows === 25 ? 'selected' : ''}>25</option>
                                    </select>
                                </div>
                            ` : ''}
                            ${showOptions ? `
                                <div class="question-type-settings" style="margin-bottom: 0.75rem; padding: 0.75rem; background: #f8f9fa; border-radius: 4px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                        <span style="font-weight: 500; font-size: 0.9rem;">選項</span>
                                        <button type="button" class="btn-add-option" onclick="addQuestionOption(${index})" style="padding: 0.4rem 0.75rem; font-size: 0.85rem;">
                                            <i class="fa-solid fa-plus"></i> 新增選項
                                        </button>
                                    </div>
                                    <div class="question-options">${optionsHtml}</div>
                                </div>
                            ` : ''}
                            ${showPrefill ? `
                                <div class="prefill-source-select" style="margin-bottom: 0.75rem;">
                                    <label style="font-size: 0.9rem;">資料庫帶入：</label>
                                    <select onchange="updatePrefillSource(${index}, this.value)" style="padding: 0.4rem 0.75rem; border: 1px solid #ced4da; border-radius: 4px; font-size: 0.9rem;">
                                        <option value="none" ${prefillSource === 'none' ? 'selected' : ''}>不使用</option>
                                        <option value="cohort" ${prefillSource === 'cohort' ? 'selected' : ''}>屆別</option>
                                        <option value="student" ${prefillSource === 'student' ? 'selected' : ''}>學生</option>
                                        <option value="advisor" ${prefillSource === 'advisor' ? 'selected' : ''}>指導老師</option>
                                    </select>
                                </div>
                            ` : ''}
                            ${qType === 'image_upload' ? `
                                <p style="font-size: 0.85rem; color: #6c757d; margin: 0.5rem 0 0 0;">學生可上傳圖片（jpg / png / webp，單檔建議 5MB 以內）</p>
                            ` : ''}
                            ${qType === 'file_upload' ? `
                                <div class="allowed-formats-row" style="margin-top: 0.5rem;">
                                    <label style="font-size: 0.9rem; display: block; margin-bottom: 0.35rem;">允許格式</label>
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem;">
                                        <label class="remark-check" style="margin: 0;"><input type="checkbox" ${allowedImage ? 'checked' : ''} onchange="updateAllowedFormats(${index}, 'image', this.checked)"><span>圖片（jpg/png/webp）</span></label>
                                        <label class="remark-check" style="margin: 0;"><input type="checkbox" ${allowedPdf ? 'checked' : ''} onchange="updateAllowedFormats(${index}, 'pdf', this.checked)"><span>PDF</span></label>
                                        <label class="remark-check" style="margin: 0;"><input type="checkbox" ${allowedWord ? 'checked' : ''} onchange="updateAllowedFormats(${index}, 'word', this.checked)"><span>Word（doc/docx）</span></label>
                                    </div>
                                </div>
                            ` : ''}
                            ${qType === 'number' ? `
                                <p style="font-size: 0.85rem; color: #6c757d; margin: 0.5rem 0 0 0;">數字或評分欄位</p>
                            ` : ''}
                            <div class="question-remark-row">
                                <label class="remark-check">
                                    <input type="checkbox" ${q.remark_enabled ? 'checked' : ''} onchange="updateQuestionRemarkEnabled(${index}, this.checked)">
                                    <span>備註（科辦可填寫）</span>
                                </label>
                                ${q.remark_enabled ? `
                                    <textarea class="remark-textarea" placeholder="科辦備註內容" oninput="updateQuestionRemarkText(${index}, this.value)">${escapeHtml(q.remark_text || '')}</textarea>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        container.querySelectorAll('.sub-questions-textarea').forEach(ta => updateSubTextLineNumbers(ta));
    }

    function toggleAdvancedSettings(qIdx) {
        // 進階設定 UI 已改為永遠展開，此函式保留以相容舊代碼，但不再執行任何動作
        return;
    }

    function onQuestionTypeChange(index, value) {
        const questions = window.__formManageData.questions;
        if (!questions[index]) return;
        if (value === '' || value === 'project_basic_block' || value === 'students_advisor' || value === 'image_upload') return;
        questions[index].type = value;
        if (questions[index].special_field === 'project_basic') {
            questions[index].special_field = 'none';
        }
        if (value === 'radio' || value === 'checkbox') {
            if (!Array.isArray(questions[index].options)) questions[index].options = [];
        }
        if (value === 'textarea') {
            if (questions[index].rows === undefined || questions[index].rows === null) questions[index].rows = 5;
            if (questions[index].textarea_display !== 'normal' && questions[index].textarea_display !== 'large') questions[index].textarea_display = 'normal';
        }
        if (value === 'image_upload') {
            if (questions[index].max_size_mb === undefined) questions[index].max_size_mb = 5;
        }
        if (value === 'file_upload') {
            if (questions[index].max_size_mb === undefined) questions[index].max_size_mb = 10;
            if (questions[index].allowed_formats === undefined || questions[index].allowed_formats === null) questions[index].allowed_formats = 'image,pdf,doc,docx';
        }
        if (value === 'number') {
            if (questions[index].min_val === undefined) questions[index].min_val = 0;
            if (questions[index].max_val === undefined) questions[index].max_val = 100;
        }
        if (value === 'sub_questions') {
            if (!Array.isArray(questions[index].subs)) questions[index].subs = [];
            if (questions[index].subs.length === 0) questions[index].subs = [{ label: '', type: 'text', rows: 1 }];
        }
        if (value === 'numbered_textarea') {
            if (questions[index].rows === undefined || questions[index].rows === null) questions[index].rows = 6;
        }
        renderQuestions();
    }

    function addSubQuestion(qIndex) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || questions[qIndex].type !== 'sub_questions') return;
        if (!Array.isArray(questions[qIndex].subs)) questions[qIndex].subs = [];
        questions[qIndex].subs.push({ label: '', type: 'text', rows: 1 });
        renderQuestions();
    }

    function addSubQuestionFromEnter(qIndex, si) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || questions[qIndex].type !== 'sub_questions' || !Array.isArray(questions[qIndex].subs)) return;
        const subs = questions[qIndex].subs;
        if (si >= 0 && si + 1 < subs.length) {
            setTimeout(function () {
                const listEl = document.querySelector('.sub-questions-list[data-qidx="' + qIndex + '"]');
                if (!listEl) return;
                const inputs = listEl.querySelectorAll('.sub-question-label-input');
                if (inputs[si + 1]) inputs[si + 1].focus();
            }, 0);
        } else {
            addSubQuestion(qIndex);
            setTimeout(function () {
                const listEl = document.querySelector('.sub-questions-list[data-qidx="' + qIndex + '"]');
                if (!listEl) return;
                const inputs = listEl.querySelectorAll('.sub-question-label-input');
                if (inputs.length > 0) inputs[inputs.length - 1].focus();
            }, 0);
        }
    }

    function removeSubQuestion(qIndex, si) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].subs) || !questions[qIndex].subs[si]) return;
        questions[qIndex].subs.splice(si, 1);
        if (questions[qIndex].subs.length === 0) questions[qIndex].subs.push({ label: '', type: 'text', rows: 1 });
        renderQuestions();
    }

    function updateSubQuestionLabel(qIndex, si, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].subs) || !questions[qIndex].subs[si]) return;
        questions[qIndex].subs[si].label = value;
    }

    function updateSubQuestionType(qIndex, si, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].subs) || !questions[qIndex].subs[si]) return;
        const valid = ['text', 'textarea'];
        questions[qIndex].subs[si].type = valid.includes(value) ? value : 'text';
        if (questions[qIndex].subs[si].type === 'textarea' && (questions[qIndex].subs[si].rows == null || questions[qIndex].subs[si].rows < 1)) questions[qIndex].subs[si].rows = 3;
        renderQuestions();
    }

    function updateSubQuestionRows(qIndex, si, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].subs) || !questions[qIndex].subs[si]) return;
        const r = parseInt(value, 10);
        questions[qIndex].subs[si].rows = (r >= 1 && r <= 20) ? r : 1;
    }

    function addSubQuestionOption(qIndex, si) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].subs) || !questions[qIndex].subs[si]) return;
        if (!Array.isArray(questions[qIndex].subs[si].options)) questions[qIndex].subs[si].options = [];
        questions[qIndex].subs[si].options.push('');
        renderQuestions();
    }

    function updateSubQuestionOption(qIndex, si, optIdx, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].subs) || !questions[qIndex].subs[si] || !Array.isArray(questions[qIndex].subs[si].options)) return;
        questions[qIndex].subs[si].options[optIdx] = value;
    }

    function removeSubQuestionOption(qIndex, si, optIdx) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].subs) || !questions[qIndex].subs[si] || !Array.isArray(questions[qIndex].subs[si].options)) return;
        questions[qIndex].subs[si].options.splice(optIdx, 1);
        renderQuestions();
    }

    function updateSubText(index, value) {
        const questions = window.__formManageData.questions;
        if (questions[index]) questions[index].sub_text = value;
    }

    function updateSubQuestionsPreview(qIndex, text) {
        const el = document.getElementById('sub-questions-preview-' + qIndex);
        if (!el) return;
        const lines = (text || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
        el.innerHTML = lines.length ? lines.map(function (line, idx) { return (idx + 1) + '. ' + escapeHtml(line); }).join('<br>') : '';
    }

    function updateSubTextLineNumbers(ta) {
        if (!ta || !ta.parentElement) return;
        const numsEl = ta.parentElement.querySelector('.sub-questions-line-numbers');
        if (!numsEl) return;
        const lines = (ta.value || '').split(/\r?\n/);
        const count = Math.max(1, lines.length);
        numsEl.textContent = Array.from({ length: count }, (_, i) => (i + 1) + '.').join('\n');
    }

    function syncSubNumbersScroll(ta) {
        if (!ta || !ta.parentElement) return;
        const numsEl = ta.parentElement.querySelector('.sub-questions-line-numbers');
        if (numsEl) numsEl.scrollTop = ta.scrollTop;
    }

    function onSpecialFieldChange(index, value) {
        const questions = window.__formManageData.questions;
        if (!questions[index]) return;
        questions[index].special_field = value;
        if (value === 'auto_readonly') {
            questions[index].auto_readonly_source = questions[index].auto_readonly_source || 'project_title';
        }
        renderQuestions();
    }

    function updateAutoReadonlySource(index, value) {
        const questions = window.__formManageData.questions;
        if (!questions[index]) return;
        questions[index].auto_readonly_source = value;
        renderQuestions();
    }

    /** 檔案上傳題型：允許格式 checkbox 變更，存成字串 image,pdf,doc,docx */
    function updateAllowedFormats(index, formatKey, checked) {
        const questions = window.__formManageData.questions;
        if (!questions[index] || (questions[index].type !== 'file_upload' && questions[index].type !== 'attachment' && questions[index].type !== 'pdf_word')) return;
        const current = (questions[index].allowed_formats != null) ? String(questions[index].allowed_formats) : '';
        let arr = current ? current.split(',').map(s => s.trim()).filter(Boolean) : [];
        const tokens = formatKey === 'image' ? ['image'] : formatKey === 'pdf' ? ['pdf'] : ['doc', 'docx'];
        if (checked) {
            tokens.forEach(t => { if (!arr.includes(t)) arr.push(t); });
        } else {
            arr = arr.filter(t => !tokens.includes(t));
        }
        questions[index].allowed_formats = arr.join(',');
        renderQuestions();
    }

    function addQuestion() {
        window.__formManageData.questions.push({
            order: window.__formManageData.questionCounter++,
            title: '',
            type: 'text',
            special_field: 'none',
            required: false,
            options: [],
            rows: 5,
            prefill_source: 'none',
            prefill_fields: []
        });
        renderQuestions();
    }

    function insertProjectBasicBlock() {
        window.__formManageData.questions.push({
            order: window.__formManageData.questionCounter++,
            type: 'project_basic_block',
            special_field: 'project_basic',
            title: '專題基本資料',
            required: false
        });
        renderQuestions();
    }

    function removeQuestion(index) {
        window.__formManageData.questions.splice(index, 1);
        // 重新排序
        window.__formManageData.questions.forEach((q, i) => {
            q.order = i;
        });
        renderQuestions();
    }

    function moveQuestion(index, direction) {
        const questions = window.__formManageData.questions;
        if (index + direction < 0 || index + direction >= questions.length) {
            return;
        }
        [questions[index], questions[index + direction]] = [questions[index + direction], questions[index]];
        questions.forEach((q, i) => {
            q.order = i;
        });
        renderQuestions();
    }

    function updateQuestionTitle(index, value) {
        const questions = window.__formManageData.questions;
        if (questions[index]) {
            questions[index].title = value;
        }
    }

    function updateQuestionType(index, value) {
        const questions = window.__formManageData.questions;
        if (!questions[index]) return;
        questions[index].type = value;
        if (value === 'radio' || value === 'checkbox') {
            if (!Array.isArray(questions[index].options)) questions[index].options = [];
        }
        if (value === 'textarea') {
            if (questions[index].rows === undefined || questions[index].rows === null) {
                questions[index].rows = 5;
            }
            if (questions[index].textarea_display !== 'normal' && questions[index].textarea_display !== 'large') {
                questions[index].textarea_display = 'normal'; // 一般多行 | 大型敘述區
            }
        }
        if (value === 'date') {
            // 日期題型不需要特殊初始化
        }
        if (value === 'table') {
            // 表格題型不需要特殊初始化
        }
        if (value === 'image_upload') {
            if (questions[index].max_size_mb === undefined) questions[index].max_size_mb = 5;
        }
        if (value === 'file_upload') {
            if (questions[index].max_size_mb === undefined) questions[index].max_size_mb = 10;
            if (questions[index].allowed_formats === undefined || questions[index].allowed_formats === null) questions[index].allowed_formats = 'image,pdf,doc,docx';
        }
        if (value === 'number') {
            if (questions[index].min_val === undefined) questions[index].min_val = 0;
            if (questions[index].max_val === undefined) questions[index].max_val = 100;
        }
        if (value === 'sub_questions') {
            if (!Array.isArray(questions[index].subs)) questions[index].subs = [{ label: '', type: 'text', rows: 1 }];
        }
        if (value === 'numbered_textarea') {
            if (questions[index].rows === undefined || questions[index].rows === null) questions[index].rows = 6;
        }
        if (value === 'students_advisor') {
            if (!Array.isArray(questions[index].students)) questions[index].students = [{ student_id: '', name: '' }];
            if (questions[index].advisor === undefined) questions[index].advisor = '';
            // 專題生/指導老師題型的專用設定
            if (!questions[index].prefill_source) questions[index].prefill_source = 'by_project'; // 預設 by_project
            if (questions[index].allow_student_edit === undefined) questions[index].allow_student_edit = true; // 預設 true
            if (!questions[index].advisor_field_type) questions[index].advisor_field_type = 'single';
        }
        // 確保其他題型的新欄位存在
        if (value !== 'students_advisor') {
            if (!questions[index].prefill_source) questions[index].prefill_source = 'none';
            if (!Array.isArray(questions[index].prefill_fields)) questions[index].prefill_fields = [];
        }
        renderQuestions();
    }

    function updateQuestionRows(index, value) {
        const questions = window.__formManageData.questions;
        if (questions[index] && (questions[index].type === 'textarea' || questions[index].type === 'numbered_textarea')) {
            const rowsValue = parseInt(value);
            if (!isNaN(rowsValue) && rowsValue >= 1) {
                questions[index].rows = rowsValue;
            } else {
                questions[index].rows = 5;
            }
            renderQuestions();
        }
    }

    function updateQuestionTextareaDisplay(index, value) {
        const questions = window.__formManageData.questions;
        if (questions[index] && questions[index].type === 'textarea' && (value === 'normal' || value === 'large')) {
            questions[index].textarea_display = value;
            if (value === 'large' && (questions[index].rows === undefined || questions[index].rows < 15)) {
                questions[index].rows = 20;
            }
            renderQuestions();
        }
    }

    /** 文字類一次選好：單行文字 / 一般多行 / 大型敘述區 */
    function setTextType(index, kind) {
        const questions = window.__formManageData.questions;
        if (!questions[index]) return;
        if (kind === 'text') {
            questions[index].type = 'text';
        } else if (kind === 'normal' || kind === 'large') {
            questions[index].type = 'textarea';
            questions[index].textarea_display = kind;
            if (questions[index].rows === undefined || questions[index].rows === null) {
                questions[index].rows = kind === 'large' ? 20 : 5;
            } else if (kind === 'large' && questions[index].rows < 15) {
                questions[index].rows = 20;
            }
        }
        renderQuestions();
    }

    function updatePrefillSource(index, value) {
        const questions = window.__formManageData.questions;
        if (questions[index]) {
            questions[index].prefill_source = value || 'none';
        }
        renderQuestions();
    }

    function updateSAStudentRows(qIndex, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || questions[qIndex].type !== 'students_advisor') return;
        const targetRows = parseInt(value) || 1;
        const currentRows = Array.isArray(questions[qIndex].students) ? questions[qIndex].students.length : 0;
        if (targetRows > currentRows) {
            // 增加行數
            for (let i = currentRows; i < targetRows; i++) {
                if (!Array.isArray(questions[qIndex].students)) questions[qIndex].students = [];
                questions[qIndex].students.push({ student_id: '', name: '' });
            }
        } else if (targetRows < currentRows) {
            // 減少行數
            questions[qIndex].students = questions[qIndex].students.slice(0, targetRows);
            if (questions[qIndex].students.length === 0) {
                questions[qIndex].students.push({ student_id: '', name: '' });
            }
        }
        renderQuestions();
    }

    function updateSAAdvisorField(qIndex, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || questions[qIndex].type !== 'students_advisor') return;
        questions[qIndex].advisor_field_type = value || 'single';
    }

    function updateSAPrefillSource(qIndex, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || questions[qIndex].type !== 'students_advisor') return;
        questions[qIndex].prefill_source = value || 'by_project';
        renderQuestions(); // 重新渲染以更新 UI 狀態
    }

    function updateSAAllowStudentEdit(qIndex, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || questions[qIndex].type !== 'students_advisor') return;
        questions[qIndex].allow_student_edit = !!value;
    }

    function updateQuestionRequired(index, value) {
        const questions = window.__formManageData.questions;
        if (questions[index]) {
            questions[index].required = value;
        }
    }

    function updateQuestionRemarkEnabled(index, value) {
        const questions = window.__formManageData.questions;
        if (!questions[index]) return;
        questions[index].remark_enabled = !!value;
        if (value && questions[index].remark_text === undefined) {
            questions[index].remark_text = '';
        }
        renderQuestions();
    }

    function updateQuestionRemarkText(index, value) {
        const questions = window.__formManageData.questions;
        if (questions[index]) {
            questions[index].remark_text = value;
        }
    }

    function addQuestionOption(index) {
        const questions = window.__formManageData.questions;
        if (questions[index]) {
            if (!Array.isArray(questions[index].options)) {
                questions[index].options = [];
            }
            questions[index].options.push('');
            renderQuestions();
        }
    }

    function updateQuestionOption(index, optIndex, value) {
        const questions = window.__formManageData.questions;
        if (questions[index] && Array.isArray(questions[index].options)) {
            questions[index].options[optIndex] = value;
        }
    }

    function removeQuestionOption(index, optIndex) {
        const questions = window.__formManageData.questions;
        if (questions[index] && Array.isArray(questions[index].options)) {
            questions[index].options.splice(optIndex, 1);
            renderQuestions();
        }
    }

    // 專題生、指導老師
    function addStudentRow(qIndex) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || questions[qIndex].type !== 'students_advisor') return;
        if (!Array.isArray(questions[qIndex].students)) questions[qIndex].students = [];
        questions[qIndex].students.push({ student_id: '', name: '' });
        renderQuestions();
    }

    function removeStudentRow(qIndex, sIndex) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].students)) return;
        questions[qIndex].students.splice(sIndex, 1);
        if (questions[qIndex].students.length === 0) questions[qIndex].students.push({ student_id: '', name: '' });
        renderQuestions();
    }

    function updateStudentCell(qIndex, sIndex, field, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex] || !Array.isArray(questions[qIndex].students) || !questions[qIndex].students[sIndex]) return;
        questions[qIndex].students[sIndex][field] = value;
    }

    function updateAdvisor(qIndex, value) {
        const questions = window.__formManageData.questions;
        if (!questions[qIndex]) return;
        questions[qIndex].advisor = value;
    }

    function openDbLookupStudent(qIndex, sIndex) {
        const questions = window.__formManageData.questions;
        const q = questions[qIndex];
        if (!q) return;

        // 檢查是否有設定帶入來源
        if (!q.prefill_source || q.prefill_source === 'none') {
            alert('請先設定此題的「資料庫帶入來源」為「學生」');
            return;
        }

        window.__formManageData.dbLookupMode = 'student';
        window.__formManageData.dbLookupPayload = { qIndex, sIndex };
        window.__formManageData.dbLookupTeachers = [];
        document.getElementById('dbLookupTitle').textContent = '從資料庫帶入 — 搜尋學生';
        document.getElementById('dbLookupSearch').placeholder = '輸入學號或姓名搜尋...';
        document.getElementById('dbLookupSearch').value = '';
        document.getElementById('dbLookupList').innerHTML = '<p class="text-muted small p-3">輸入學號或姓名後搜尋</p>';
        document.getElementById('dbLookupModal').classList.add('show');
        document.getElementById('dbLookupSearch').focus();
    }

    async function openDbLookupAdvisor(qIndex) {
        const questions = window.__formManageData.questions;
        const q = questions[qIndex];
        if (!q) return;

        // 檢查是否有設定帶入來源
        if (!q.prefill_source || q.prefill_source === 'none') {
            alert('請先設定此題的「資料庫帶入來源」為「指導老師」');
            return;
        }

        window.__formManageData.dbLookupMode = 'advisor';
        window.__formManageData.dbLookupPayload = { qIndex };
        document.getElementById('dbLookupTitle').textContent = '從資料庫帶入 — 選擇指導老師';
        document.getElementById('dbLookupSearch').placeholder = '輸入姓名篩選...';
        document.getElementById('dbLookupSearch').value = '';
        document.getElementById('dbLookupList').innerHTML = '<p class="text-muted small p-3">載入中...</p>';
        document.getElementById('dbLookupModal').classList.add('show');
        try {
            const r = await fetch('api.php?do=get_teachers_list');
            const d = await r.json();
            const list = document.getElementById('dbLookupList');
            if (!d.ok || !d.teachers || !d.teachers.length) {
                list.innerHTML = '<p class="text-muted small p-3">尚無指導老師資料</p>';
                return;
            }
            window.__formManageData.dbLookupTeachers = d.teachers;
            filterTeachersLookup();
        } catch (e) {
            document.getElementById('dbLookupList').innerHTML = '<p class="text-danger small p-3">載入失敗</p>';
        }
    }

    async function openDbLookupCohort(qIndex) {
        const questions = window.__formManageData.questions;
        const q = questions[qIndex];
        if (!q) return;

        // 檢查是否有設定帶入來源
        if (!q.prefill_source || q.prefill_source !== 'cohort') {
            alert('請先設定此題的「資料庫帶入來源」為「屆別」');
            return;
        }

        window.__formManageData.dbLookupMode = 'cohort';
        window.__formManageData.dbLookupPayload = { qIndex };
        document.getElementById('dbLookupTitle').textContent = '從資料庫帶入 — 選擇屆別';
        document.getElementById('dbLookupSearch').placeholder = '輸入屆別名稱搜尋...';
        document.getElementById('dbLookupSearch').value = '';
        document.getElementById('dbLookupList').innerHTML = '<p class="text-muted small p-3">載入中...</p>';
        document.getElementById('dbLookupModal').classList.add('show');
        try {
            const r = await fetch('api.php?do=get_cohorts_list');
            const d = await r.json();
            const list = document.getElementById('dbLookupList');
            if (!d.ok || !d.cohorts || !d.cohorts.length) {
                list.innerHTML = '<p class="text-muted small p-3">尚無屆別資料</p>';
                return;
            }
            window.__formManageData.dbLookupCohorts = d.cohorts;
            filterCohortsLookup();
        } catch (e) {
            document.getElementById('dbLookupList').innerHTML = '<p class="text-danger small p-3">載入失敗</p>';
        }
    }

    function filterCohortsLookup() {
        const list = document.getElementById('dbLookupList');
        if (!list) return;
        const q = (document.getElementById('dbLookupSearch').value || '').trim().toLowerCase();
        const arr = q
            ? (window.__formManageData.dbLookupCohorts || []).filter(c => (c.cohort_name || '').toLowerCase().includes(q))
            : (window.__formManageData.dbLookupCohorts || []);
        list.innerHTML = arr.map(c => `
            <div class="db-lookup-item" data-id="${escapeHtml(String(c.cohort_ID))}" data-name="${escapeHtml(c.cohort_name || '')}">
                <span>${escapeHtml(c.cohort_name || c.cohort_ID)}</span>
                <span class="text-muted small">${escapeHtml(c.year_label || '')}</span>
            </div>
        `).join('') || '<p class="text-muted small p-3">無符合結果</p>';
        list.querySelectorAll('.db-lookup-item').forEach(el => {
            el.addEventListener('click', () => pickCohort(el.dataset.id, el.dataset.name));
        });
    }

    function pickCohort(id, name) {
        if (window.__formManageData.dbLookupMode !== 'cohort' || !window.__formManageData.dbLookupPayload) return;
        const questions = window.__formManageData.questions;
        const q = questions[window.__formManageData.dbLookupPayload.qIndex];
        if (q) {
            // 將屆別資訊存入題目
            q.prefilled_cohort_id = id;
            q.prefilled_cohort_name = name;
            renderQuestions();
        }
        closeDbLookup();
    }

    function filterTeachersLookup() {
        const list = document.getElementById('dbLookupList');
        if (!list) return;
        const q = (document.getElementById('dbLookupSearch').value || '').trim().toLowerCase();
        const arr = q
            ? (window.__formManageData.dbLookupTeachers || []).filter(t => (t.u_name || '').toLowerCase().includes(q) || (String(t.u_ID || '')).toLowerCase().includes(q))
            : (window.__formManageData.dbLookupTeachers || []);
        list.innerHTML = arr.map(t => `
            <div class="db-lookup-item" data-id="${escapeHtml(String(t.u_ID))}" data-name="${escapeHtml(t.u_name || '')}">
                <span>${escapeHtml(t.u_name || t.u_ID)}</span>
                <span class="text-muted small">${escapeHtml(String(t.u_ID))}</span>
            </div>
        `).join('') || '<p class="text-muted small p-3">無符合結果</p>';
        list.querySelectorAll('.db-lookup-item').forEach(el => {
            el.addEventListener('click', () => pickTeacher(el.dataset.id, el.dataset.name));
        });
    }

    function pickTeacher(id, name) {
        if (window.__formManageData.dbLookupMode !== 'advisor' || !window.__formManageData.dbLookupPayload) return;
        const questions = window.__formManageData.questions;
        const q = questions[window.__formManageData.dbLookupPayload.qIndex];
        if (q) {
            q.advisor = name || id;
            renderQuestions();
        }
        closeDbLookup();
    }

    // 初始化事件監聽器（只執行一次）
    if (!window.__formManageEventListenersInited) {
        window.__formManageEventListenersInited = true;
        const dbLookupSearchEl = document.getElementById('dbLookupSearch');
        if (dbLookupSearchEl) {
            dbLookupSearchEl.addEventListener('input', function () {
                const q = this.value.trim();
                const list = document.getElementById('dbLookupList');
                if (window.__formManageData.dbLookupMode === 'student') {
                    if (window.__formManageData.searchStudentsTimer) {
                        clearTimeout(window.__formManageData.searchStudentsTimer);
                    }
                    if (q.length < 1) {
                        list.innerHTML = '<p class="text-muted small p-3">輸入學號或姓名後搜尋</p>';
                        return;
                    }
                    window.__formManageData.searchStudentsTimer = setTimeout(async () => {
                        try {
                            const r = await fetch('api.php?do=search_students&q=' + encodeURIComponent(q));
                            const d = await r.json();
                            if (!d.ok || !d.students) {
                                list.innerHTML = '<p class="text-muted small p-3">無符合結果</p>';
                                return;
                            }
                            list.innerHTML = d.students.map(s => `
                                <div class="db-lookup-item" data-id="${escapeHtml(String(s.u_ID))}" data-name="${escapeHtml(s.u_name || '')}">
                                    <span>${escapeHtml(s.u_name || s.u_ID)}</span>
                                    <span class="text-muted small">${escapeHtml(String(s.u_ID))}</span>
                                </div>
                            `).join('') || '<p class="text-muted small p-3">無符合結果</p>';
                            list.querySelectorAll('.db-lookup-item').forEach(el => {
                                el.addEventListener('click', () => pickStudent(el.dataset.id, el.dataset.name));
                            });
                        } catch (e) {
                            list.innerHTML = '<p class="text-danger small p-3">搜尋失敗</p>';
                        }
                    }, 300);
                } else if (window.__formManageData.dbLookupMode === 'advisor') {
                    filterTeachersLookup();
                } else if (window.__formManageData.dbLookupMode === 'cohort') {
                    filterCohortsLookup();
                }
            });
        }
    }

    function pickStudent(id, name) {
        if (window.__formManageData.dbLookupMode !== 'student' || !window.__formManageData.dbLookupPayload) return;
        const questions = window.__formManageData.questions;
        const q = questions[window.__formManageData.dbLookupPayload.qIndex];
        const s = q && Array.isArray(q.students) ? q.students[window.__formManageData.dbLookupPayload.sIndex] : null;
        if (s) {
            s.student_id = id;
            s.name = name;
            renderQuestions();
        }
        closeDbLookup();
    }

    function closeDbLookup() {
        window.__formManageData.dbLookupMode = null;
        window.__formManageData.dbLookupPayload = null;
        document.getElementById('dbLookupModal').classList.remove('show');
    }

    // 初始化事件監聽器（只執行一次）
    if (!window.__formManageModalListenersInited) {
        window.__formManageModalListenersInited = true;
        const btnCloseDbLookup = document.getElementById('btnCloseDbLookup');
        const dbLookupModal = document.getElementById('dbLookupModal');
        if (btnCloseDbLookup) {
            btnCloseDbLookup.addEventListener('click', closeDbLookup);
        }
        if (dbLookupModal) {
            dbLookupModal.addEventListener('click', function (e) {
                if (e.target === this) closeDbLookup();
            });
        }
    }

    // 工具函數
    function closeModal() {
        const formModal = document.getElementById('formModal');
        if (formModal) {
            formModal.classList.remove('show');
            document.body.classList.remove('modal-open');
            window.__formManageData.initialSnapshot = undefined;
        }
    }

    function showError(message) {
        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: '錯誤',
                text: message
            });
        } else {
            alert(message);
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatDateTimeLocal(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    function setCheckboxValue(el, value, defaultValue = false) {
        if (!el) return;

        const v = (value === undefined || value === null) ? defaultValue : value;
        const checked = (
            v === true ||
            v === 1 ||
            v === '1' ||
            v === 'true'
        );

        el.checked = checked;
        el.defaultChecked = checked;

        if (checked) {
            el.setAttribute('checked', 'checked');
        } else {
            el.removeAttribute('checked');
        }
    }
    function syncExresultCheckbox(schema) {
        const formEl = document.getElementById('formForm');
        if (!formEl) return;

        const exresultEl = formEl.querySelector('#form_exresultdata');
        if (!exresultEl) {
            console.warn('找不到 #form_exresultdata 的 checkbox');
            return;
        }

        const ex = schema && typeof schema === 'object' ? (schema.exresultdata ?? false) : false;
        const checked = (
            ex === true ||
            ex === 1 ||
            ex === '1' ||
            ex === 'true'
        );

        exresultEl.checked = checked;
        exresultEl.defaultChecked = checked;

        if (checked) {
            exresultEl.setAttribute('checked', 'checked');
        } else {
            exresultEl.removeAttribute('checked');
        }

        console.log('syncExresultCheckbox =>', ex, checked, exresultEl);
    }
    // 取得目前表單狀態（用於與開啟時的快照比對）
    function getCurrentFormState() {
        const gradesSelect = document.getElementById('doc_target_grades');
        const groupsSelect = document.getElementById('doc_target_groups');
        const questionsCopy = (window.__formManageData.questions || []).slice();
        questionsCopy.sort((a, b) => (a.order || 0) - (b.order || 0));
        return {
            document_id: document.getElementById('document_id')?.value ?? '',
            document_name: (document.getElementById('document_name')?.value ?? '').trim(),
            doc_header: (document.getElementById('doc_header')?.value ?? '').trim(),
            document_category: '審查文件',
            is_required: document.getElementById('is_required')?.value === '1',
            open_datetime: document.getElementById('open_datetime')?.value ?? '',
            close_datetime: document.getElementById('close_datetime')?.value ?? '',
            document_status: true,
            document_remark: '',
            doc_target_all: 0,
            doc_target_grades: gradesSelect ? Array.from(gradesSelect.selectedOptions).map(o => o.value).sort() : [],
            doc_target_groups: groupsSelect ? Array.from(groupsSelect.selectedOptions).map(o => o.value).sort() : [],
            doc_target_classes: [],
            questions: JSON.parse(JSON.stringify(questionsCopy)),
            exresultdata: document.getElementById('form_exresultdata')?.checked || false
        };
    }

    // 儲存開啟 modal 時的初始快照（僅在真的有修改時才視為未儲存變更）
    function saveInitialSnapshot() {
        window.__formManageData.initialSnapshot = JSON.stringify(getCurrentFormState());
    }

    // 檢查是否有未儲存的變更（比對目前狀態與開啟時的快照；僅點編輯未改動則不視為有變更）
    function hasUnsavedChanges() {
        if (window.__formManageData.initialSnapshot === undefined) {
            const docName = document.getElementById('document_name')?.value.trim() || '';
            const questions = window.__formManageData.questions || [];
            return docName.length > 0 || questions.length > 0;
        }
        try {
            return JSON.stringify(getCurrentFormState()) !== window.__formManageData.initialSnapshot;
        } catch (e) {
            return true;
        }
    }

    // 自動儲存表單資料到 localStorage（防止意外重新載入）
    function autoSaveFormData() {
        try {
            const formData = {
                document_id: document.getElementById('document_id')?.value || '0',
                document_name: document.getElementById('document_name')?.value || '',
                doc_header: document.getElementById('doc_header')?.value || '',
                document_category: '審查文件',
                is_required: document.getElementById('is_required')?.value === '1',
                open_datetime: document.getElementById('open_datetime')?.value || '',
                close_datetime: document.getElementById('close_datetime')?.value || '',
                document_status: true,
                document_remark: '',
                doc_target_all: 0,
                doc_target_grades: Array.from(document.getElementById('doc_target_grades')?.selectedOptions || []).map(opt => opt.value),
                doc_target_classes: Array.from(document.getElementById('doc_target_classes')?.selectedOptions || []).map(opt => opt.value),
                doc_target_groups: Array.from(document.getElementById('doc_target_groups')?.selectedOptions || []).map(opt => opt.value),
                questions: window.__formManageData.questions || [],
                questionCounter: window.__formManageData.questionCounter || 0,
                timestamp: Date.now(),
                exresultdata: document.getElementById('form_exresultdata')?.checked || false,
            };
            localStorage.setItem('form_manage_draft', JSON.stringify(formData));
        } catch (e) {
            console.warn('自動儲存失敗:', e);
        }
    }

    // 載入自動儲存的表單資料
    function loadAutoSavedFormData() {
        try {
            const saved = localStorage.getItem('form_manage_draft');
            if (!saved) return false;

            const formData = JSON.parse(saved);
            // 檢查是否為最近 24 小時內的資料
            if (Date.now() - formData.timestamp > 24 * 60 * 60 * 1000) {
                localStorage.removeItem('form_manage_draft');
                return false;
            }

            // 詢問用戶是否要恢復
            if (window.Swal) {
                Swal.fire({
                    icon: 'question',
                    title: '發現未完成的表單',
                    text: '偵測到之前未完成的表單資料，是否要恢復？',
                    showCancelButton: true,
                    confirmButtonText: '恢復',
                    cancelButtonText: '放棄',
                    confirmButtonColor: '#007bff',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        restoreFormData(formData);
                    } else {
                        localStorage.removeItem('form_manage_draft');
                    }
                });
            } else {
                if (confirm('偵測到之前未完成的表單資料，是否要恢復？')) {
                    restoreFormData(formData);
                } else {
                    localStorage.removeItem('form_manage_draft');
                }
            }
            return true;
        } catch (e) {
            console.warn('載入自動儲存失敗:', e);
            localStorage.removeItem('form_manage_draft');
            return false;
        }
    }

    // 恢復表單資料
    function restoreFormData(formData) {
        document.getElementById('document_id').value = formData.document_id || '0';
        document.getElementById('document_name').value = formData.document_name || '';
        const docHeaderEl = document.getElementById('doc_header');
        if (docHeaderEl) docHeaderEl.value = formData.doc_header || '';
        document.getElementById('is_required').value = formData.is_required ? '1' : '0';
        document.getElementById('open_datetime').value = formData.open_datetime || '';
        document.getElementById('close_datetime').value = formData.close_datetime || '';
        syncExresultCheckbox({ exresultdata: formData.exresultdata ?? false });        // 恢復目標設定（學級、類組）
        const gradesSelect = document.getElementById('doc_target_grades');
        const groupsSelect = document.getElementById('doc_target_groups');
        if (gradesSelect && formData.doc_target_grades) {
            Array.from(gradesSelect.options).forEach(opt => {
                opt.selected = formData.doc_target_grades.includes(opt.value);
            });
        }
        if (groupsSelect && formData.doc_target_groups) {
            Array.from(groupsSelect.options).forEach(opt => {
                opt.selected = formData.doc_target_groups.includes(opt.value);
            });
        }

        // 恢復題目
        window.__formManageData.questions = formData.questions || [];
        window.__formManageData.questionCounter = formData.questionCounter || 0;

        updateTargetSettingsVisibility();
        renderQuestions();

        // 開啟 modal
        document.getElementById('modalTitle').textContent = parseInt(formData.document_id) > 0 ? '編輯表單' : '新增表單';
        document.getElementById('formModal').classList.add('show');
    }

    // 清除自動儲存的資料
    function clearAutoSavedFormData() {
        try {
            localStorage.removeItem('form_manage_draft');
        } catch (e) {
            console.warn('清除自動儲存失敗:', e);
        }
    }

    // 安全關閉 modal（檢查未儲存變更）
    function safeCloseModal() {
        if (hasUnsavedChanges()) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: '確認離開',
                    text: '您有未儲存的變更，確定要離開嗎？',
                    showCancelButton: true,
                    confirmButtonText: '離開',
                    cancelButtonText: '取消',
                    confirmButtonColor: '#dc3545',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        closeModal();
                    }
                });
            } else {
                if (confirm('您有未儲存的變更，確定要離開嗎？')) {
                    closeModal();
                }
            }
        } else {
            closeModal();
        }
    }

    // 事件綁定（只執行一次）
    if (!window.__formManageButtonListenersInited) {
        window.__formManageButtonListenersInited = true;
        const btnAddForm = document.getElementById('btnAddForm');
        const btnCloseModal = document.getElementById('btnCloseModal');
        const btnCancelForm = document.getElementById('btnCancelForm');
        const btnSaveForm = document.getElementById('btnSaveForm');
        const btnAddQuestion = document.getElementById('btnAddQuestion');
        const documentStatus = document.getElementById('document_status');
        const formModal = document.getElementById('formModal');
        const formForm = document.getElementById('formForm');

        // 阻止表單預設提交行為（防止頁面跳轉）
        if (formForm) {
            formForm.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                // 觸發儲存按鈕的點擊事件
                saveForm();
                return false;
            });

            // 監聽表單欄位變化，自動儲存
            const formInputs = formForm.querySelectorAll('input, select, textarea');
            formInputs.forEach(input => {
                input.addEventListener('change', autoSaveFormData);
                input.addEventListener('input', function () {
                    // 使用 debounce 避免過於頻繁的儲存
                    clearTimeout(window.__formManageAutoSaveTimer);
                    window.__formManageAutoSaveTimer = setTimeout(autoSaveFormData, 1000);
                });
            });
        }

        if (btnAddForm) btnAddForm.addEventListener('click', addForm);
        if (btnAddQuestion) btnAddQuestion.addEventListener('click', addQuestion);
        // 取消/儲存/關閉由 app.js 在 document.body 委派處理，切頁再回來也能點

        const btnToggleAttachments = document.getElementById('btnToggleAttachments');
        if (btnToggleAttachments) {
            btnToggleAttachments.addEventListener('click', function () {
                const body = document.getElementById('attachmentManagementBody');
                if (body) {
                    body.classList.toggle('show');
                    btnToggleAttachments.classList.toggle('expanded', body.classList.contains('show'));
                    btnToggleAttachments.setAttribute('aria-expanded', body.classList.contains('show'));
                }
            });
        }
        const btnUploadAttachment = document.getElementById('btnUploadAttachment');
        if (btnUploadAttachment) {
            btnUploadAttachment.addEventListener('click', async function () {
                const docId = parseInt(document.getElementById('document_id').value, 10);
                if (!docId || docId <= 0) {
                    if (window.Swal) Swal.fire({ icon: 'warning', title: '請先儲存表單', text: '儲存表單後才能上傳附件' });
                    else alert('請先儲存表單後再上傳附件');
                    return;
                }
                const fileInput = document.getElementById('doc_attachment_file');
                if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                    if (window.Swal) Swal.fire({ icon: 'warning', title: '請選擇檔案', text: '請選擇 PDF 檔案' });
                    else alert('請選擇 PDF 檔案');
                    return;
                }
                const displayName = (document.getElementById('doc_attachment_name') && document.getElementById('doc_attachment_name').value.trim()) || '附件.pdf';
                const formData = new FormData();
                formData.append('do', 'upload_document_form_attachment');
                formData.append('doc_ID', docId);
                formData.append('display_name', displayName);
                formData.append('file', fileInput.files[0]);
                btnUploadAttachment.disabled = true;
                try {
                    const res = await fetch('api.php?do=upload_document_form_attachment', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.ok) {
                        const el = document.getElementById('doc_attachment_current');
                        if (el) el.textContent = '目前附件：' + (data.display_name || displayName);
                        if (window.Swal) Swal.fire({ icon: 'success', title: '已上傳', timer: 1500, showConfirmButton: false });
                        else alert('附件已上傳');
                        fileInput.value = '';
                    } else {
                        if (window.Swal) Swal.fire({ icon: 'error', title: '上傳失敗', text: data.msg || '' });
                        else alert(data.msg || '上傳失敗');
                    }
                } catch (e) {
                    if (window.Swal) Swal.fire({ icon: 'error', title: '上傳失敗', text: e.message });
                    else alert('上傳失敗');
                }
                btnUploadAttachment.disabled = false;
            });
        }

        // 狀態切換標籤
        if (documentStatus) {
            documentStatus.addEventListener('change', function () {
                const statusLabel = document.getElementById('statusLabel');
                if (statusLabel) {
                    statusLabel.textContent = this.checked ? '啟用' : '停用';
                }
            });
        }

        // 點擊 modal 外部關閉（也要檢查未儲存變更）
        if (formModal) {
            formModal.addEventListener('click', function (e) {
                if (e.target === this) {
                    safeCloseModal();
                }
            });
        }

        // 防止意外頁面跳轉（beforeunload 事件）
        window.addEventListener('beforeunload', function (e) {
            // 只有在 modal 開啟且有未儲存變更時才提示
            const formModal = document.getElementById('formModal');
            if (formModal && formModal.classList.contains('show') && hasUnsavedChanges()) {
                e.preventDefault();
                e.returnValue = '您有未儲存的表單變更，確定要離開嗎？';
                return e.returnValue;
            }
        });
    }

    // 載入目標設定選項（學級、類組、班級）
    async function loadTargetOptions() {
        try {
            // 載入學級列表（只顯示正在進行中的）
            const gradesRes = await fetch('api.php?do=get_grades_list');
            const gradesData = await gradesRes.json();
            if (gradesData.ok && gradesData.grades) {
                window.__formManageData.gradesList = gradesData.grades;
                const gradesSelect = document.getElementById('doc_target_grades');
                if (gradesSelect) {
                    gradesSelect.innerHTML = gradesData.grades.map(g => {
                        const gradeLabel = g.cohort_name || (g.enroll_grade + '級');
                        return `<option value="${escapeHtml(g.enroll_grade)}">${escapeHtml(gradeLabel)}</option>`;
                    }).join('');
                }
            }

            // 載入類組列表
            const groupsRes = await fetch('api.php?do=get_groups_list');
            const groupsData = await groupsRes.json();
            if (groupsData.ok && groupsData.groups) {
                window.__formManageData.groupsList = groupsData.groups;
                const groupsSelect = document.getElementById('doc_target_groups');
                if (groupsSelect) {
                    groupsSelect.innerHTML = groupsData.groups.map(g =>
                        `<option value="${escapeHtml(g.group_ID)}">${escapeHtml(g.group_name || g.group_ID)}</option>`
                    ).join('');
                }
            }

            // 載入班級列表
            const classesRes = await fetch('api.php?do=get_classes_list');
            const classesData = await classesRes.json();
            if (classesData.ok && classesData.classes) {
                window.__formManageData.classesList = classesData.classes;
                const classesSelect = document.getElementById('doc_target_classes');
                if (classesSelect) {
                    classesSelect.innerHTML = classesData.classes.map(c =>
                        `<option value="${escapeHtml(c.c_ID)}">${escapeHtml(c.c_name || c.c_ID)}</option>`
                    ).join('');
                }
            }
        } catch (error) {
            console.error('載入目標設定選項失敗:', error);
            // 顯示錯誤訊息給用戶
            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: '載入選項失敗',
                    text: '無法載入目標設定選項，請重新整理頁面或聯繫管理員',
                    timer: 3000,
                    showConfirmButton: false
                });
            } else {
                alert('載入目標設定選項失敗，請檢查瀏覽器控制台');
            }
        }
    }

    // 頁面載入時立即載入表單列表
    // 使用多種方式確保立即執行
    function initFormManage() {
        if (window.__formManageNeedReload) {
            window.__formManageNeedReload = false;
        }
        // 載入目標設定選項
        loadTargetOptions();
        // 檢查是否有自動儲存的資料
        setTimeout(() => {
            loadAutoSavedFormData();
        }, 500);
        // 立即執行，不等待 DOMContentLoaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadForms);
        } else {
            // DOM 已經準備好，立即執行
            loadForms();
        }
    }

    // 立即嘗試執行
    initFormManage();

    // 如果頁面是通過 hash 路由動態載入的，也需要監聽
    if (window.addEventListener) {
        window.addEventListener('hashchange', function () {
            if (window.location.hash.includes('form_manage')) {
                setTimeout(loadForms, 100);
            }
        });
    }

    // 將需要在 HTML 中調用的函數暴露到全局作用域（含刪除按鈕、取消/儲存，切頁再回來時 inline onclick 仍可觸發）
    window.editForm = editForm;
    window.toggleStatus = toggleStatus;
    window.deleteForm = deleteForm;
    window.editForm = editForm;
    window.duplicateForm = duplicateForm;
    window.loadForms = loadForms;
    window.addForm = addForm;
    window.saveForm = saveForm;
    window.safeCloseModal = safeCloseModal;
    window.addQuestion = addQuestion;
    window.removeQuestion = removeQuestion;
    window.moveQuestion = moveQuestion;
    window.updateQuestionTitle = updateQuestionTitle;
    window.updateQuestionType = updateQuestionType;
    window.toggleAdvancedSettings = toggleAdvancedSettings;
    window.onQuestionTypeChange = onQuestionTypeChange;
    window.onSpecialFieldChange = onSpecialFieldChange;
    window.updateAutoReadonlySource = updateAutoReadonlySource;
    window.updateSubText = updateSubText;
    window.updateSubQuestionsPreview = updateSubQuestionsPreview;
    window.updateSubTextLineNumbers = updateSubTextLineNumbers;
    window.syncSubNumbersScroll = syncSubNumbersScroll;
    window.insertProjectBasicBlock = insertProjectBasicBlock;
    window.updateQuestionRequired = updateQuestionRequired;
    window.updateQuestionRows = updateQuestionRows;
    window.updateQuestionTextareaDisplay = updateQuestionTextareaDisplay;
    window.setTextType = setTextType;
    window.updateQuestionRemarkEnabled = updateQuestionRemarkEnabled;
    window.updateQuestionRemarkText = updateQuestionRemarkText;
    window.updateAllowedFormats = updateAllowedFormats;
    window.addQuestionOption = addQuestionOption;
    window.updateQuestionOption = updateQuestionOption;
    window.removeQuestionOption = removeQuestionOption;
    window.addStudentRow = addStudentRow;
    window.removeStudentRow = removeStudentRow;
    window.updateStudentCell = updateStudentCell;
    window.updateAdvisor = updateAdvisor;
    window.updateSAStudentRows = updateSAStudentRows;
    window.updateSAAdvisorField = updateSAAdvisorField;
    window.updateSAPrefillSource = updateSAPrefillSource;
    window.updateSAAllowStudentEdit = updateSAAllowStudentEdit;
    window.openDbLookupStudent = openDbLookupStudent;
    window.openDbLookupAdvisor = openDbLookupAdvisor;
    window.closeDbLookup = closeDbLookup;
})();