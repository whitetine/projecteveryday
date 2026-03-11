// js/team_apply.js
const { createApp } = Vue;

const app = createApp({
    data() {
        return {
            config: window.TEAM_APPLY_CONFIG || { apiPath: '../api.php', u_ID: '' },
            isSubmitting: false,
            isReadonly: false,
            teachers: [],
            form: {
                project_name: '',
                teacher_id: '',
                teacher_id_2: '',
                teacher_id_3: '',
                co_teacher_id: '',
                group_id: '',
                comment: '',
                members: []
            },
            applyConfig: null,
            fieldCtrl: {},
            cohort_ID: 0,
            applicant: null,

            memberInput: '',
            imageFile: null,
            imagePreviewUrl: null,
            reviewData: null,
            draftTapId: null

        };
    },
    async mounted() {
        await this.loadApplyConfig();
        await this.loadTeachers();           // teacher list 也會用到 cohort/taf
        await this.checkExistingApplication();
    },

    methods: {
        async loadTeachers() {
            try {
                const res = await fetch(`${this.config.apiPath}?do=get_teachers`);
                const data = await res.json();
                if (data.ok) this.teachers = data.teachers || [];
            } catch (e) { console.error(e); }
        },
        async loadApplyConfig() {
            const res = await fetch(`${this.config.apiPath}?do=get_apply_form_config`);
            const data = await res.json();
            if (!data.ok) {
                Swal.fire({ icon: 'error', title: '無法載入申請設定', text: data.msg });
                return;
            }
            this.applyConfig = data.taf;
            this.fieldCtrl = data.fields || {};
            this.cohort_ID = data.cohort_ID || 0;
            // Console 顯示表單屆別與 ID（除錯用）
            const tafId = this.applyConfig?.taf_ID ?? data.taf?.taf_ID ?? '-';
            const cohortLabel = this.applyConfig?.cohort_label ?? data.cohort_label ?? '-';
            console.log('[組隊申請表] 表單ID:', tafId, '| 屆別:', cohortLabel, '| cohort_ID:', this.cohort_ID);
        },
        fieldShow(key) { return this.fieldCtrl?.[key]?.show !== false; },
        fieldRequire(key) { return this.fieldCtrl?.[key]?.require === true; },
        async checkExistingApplication() {
            try {
                const res = await fetch(`${this.config.apiPath}?do=get_my_application`);
                const data = await res.json();
                if (data.ok && data.application) {
                    const app = data.application;
                    this.reviewData = app;

                    // 回填資料
                    this.form.project_name = app.tap_name;
                    this.form.comment = app.tap_des;
                    this.form.group_id = String(app.group?.group_ID ?? app.tap_group ?? '');
                    if (app.teacher) this.form.teacher_id = app.teacher.u_ID;
                    this.form.teacher_id_2 = app.teacher_2_id || (app.teacher_2?.u_ID) || '';
                    this.form.teacher_id_3 = app.teacher_3_id || (app.teacher_3?.u_ID) || '';
                    this.form.co_teacher_id = app.co_teacher_id || '';
                    if (app.applicant) this.applicant = app.applicant;
                    if (app.members) this.form.members = app.members;

                    if (app.tap_url) {
                        let url = app.tap_url;
                        if (!url.startsWith('http') && !url.startsWith('/')) url = '../' + url;
                        this.imagePreviewUrl = url;
                    }

                    // 狀態判斷: 1(審核中), 3(通過) -> 鎖定; 2(退件), 4(暫存) -> 可編輯
                    const status = Number(app.tap_status);
                    if ([1, 3].includes(status)) {
                        this.isReadonly = true;
                    }
                    this.draftTapId = app.tap_status === 4 ? app.tap_ID : null;
                }
            } catch (e) { console.error(e); }
        },
        async addMember() {
            const sid = this.memberInput.trim();
            if (!sid) return Swal.fire('請輸入學號');
            if (sid === this.config.u_ID) return Swal.fire('您已在名單中');
            if (this.form.members.some(m => m.u_ID === sid)) return Swal.fire('成員已存在');

            try {
                const fd = new FormData();
                fd.append('student_id', sid);
                if (this.cohort_ID) fd.append('cohort_ID', this.cohort_ID);
                const res = await fetch(`${this.config.apiPath}?do=get_student_info`, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) {
                    this.form.members.push(data.student);
                    this.memberInput = '';
                } else {
                    Swal.fire({ icon: 'error', title: '錯誤', text: data.msg || '找不到學生' });
                }
            } catch (e) { Swal.fire('查詢失敗'); }
        },
        removeMember(index) {
            this.form.members.splice(index, 1);
        },
        handleImageUpload(e) {
            const file = e.target.files[0];
            if (!file) return;
            this.imageFile = file;
            this.imagePreviewUrl = URL.createObjectURL(file);
        },
        removeImage() {
            this.imageFile = null;
            this.imagePreviewUrl = null;
            const input = document.getElementById('apply_image');
            if (input) input.value = '';
        },
        async saveDraft() {
            if (this.isSubmitting) return;
            if (!this.form.teacher_id) return Swal.fire({ icon: 'warning', title: '請選擇指導老師' });
            this.isSubmitting = true;
            try {
                const fd = new FormData();
                fd.append('project_name', this.form.project_name);
                fd.append('teacher_id', this.form.teacher_id);
                fd.append('teacher_id_2', this.form.teacher_id_2 || '');
                fd.append('teacher_id_3', this.form.teacher_id_3 || '');
                fd.append('co_teacher_id', this.form.co_teacher_id);
                fd.append('group_id', this.form.group_id);
                fd.append('comment', this.form.comment);
                fd.append('member_ids', JSON.stringify(this.form.members.map(m => m.u_ID)));
                if (this.imageFile) fd.append('apply_image', this.imageFile);
                if (this.draftTapId) fd.append('tap_ID', this.draftTapId);

                const res = await fetch(`${this.config.apiPath}?do=save_draft`, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) {
                    this.draftTapId = data.tap_ID;
                    Swal.fire({ icon: 'success', title: '暫存成功', timer: 1500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: '暫存失敗', text: data.msg });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: '系統錯誤', text: e.message });
            } finally {
                this.isSubmitting = false;
            }
        },
        getFormDataForExport() {
            const esc = s => String(s || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const teacherName = (this.teachers || []).find(t => t.u_ID === this.form.teacher_id)?.u_name || this.form.teacher_id || '';
            const applicant = this.applicant || {
                u_ID: this.config.u_ID,
                u_name: this.config.u_name || this.config.u_ID,
                class_label: this.applyConfig?.applicant_class_label || ''
            };
            const allMembers = [applicant, ...(this.form.members || [])];
            const memberRows = [];
            for (let i = 0; i < 4; i++) {
                const m = allMembers[i] || {};
                const classLabel = m.class_label || '';
                memberRows.push(`<tr><td style="text-align:center;vertical-align:middle;border:1px solid #000">${esc(classLabel)}</td><td style="text-align:center;vertical-align:middle;border:1px solid #000">${esc(m.u_ID)}</td><td style="text-align:center;vertical-align:middle;border:1px solid #000">${esc(m.u_name)}</td></tr>`);
            }
            return {
                projectName: esc(this.form.project_name),
                teacherName: esc(teacherName),
                memberRowsHtml: memberRows.join(''),
                note: (this.applyConfig?.note || '').trim()
            };
        },
        getExportHtml() {
            const esc = s => String(s || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const d = this.getFormDataForExport();
            const docTitle = '康寧學校財團法人康寧大學 資管科' + (this.applyConfig?.title || '專題指導申請單');
            const noteHtml = d.note ? `<p style="margin-top:16px;font-size:13px">註：${esc(d.note)}</p>` : '';
            return `<div style="font-family:Microsoft JhengHei,sans-serif;padding:24px;font-size:14px;max-width:600px;margin:0 auto">
<h2 style="text-align:center;margin-bottom:24px;font-size:18px">${esc(docTitle)}</h2>
<table border="1" cellpadding="10" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #000">
<tr>
  <td width="80" style="text-align:center;vertical-align:middle;border:1px solid #000">專題名稱</td>
  <td colspan="3" style="text-align:center;vertical-align:middle;border:1px solid #000">${d.projectName || ''}</td>
</tr>
<tr>
  <td rowspan="5" style="text-align:center;vertical-align:middle;border:1px solid #000">組員</td>
  <td width="100" style="text-align:center;vertical-align:middle;border:1px solid #000">班級</td>
  <td width="120" style="text-align:center;vertical-align:middle;border:1px solid #000">學號</td>
  <td width="120" style="text-align:center;vertical-align:middle;border:1px solid #000">姓名</td>
</tr>
${d.memberRowsHtml}
<tr>
  <td style="text-align:center;vertical-align:middle;border:1px solid #000">指導老師簽名</td>
  <td colspan="3" style="text-align:center;vertical-align:middle;border:1px solid #000;min-height:50px">&nbsp;</td>
</tr>
</table>
${noteHtml}
</div>`;
        },
        downloadPDF() {
            const html = this.getExportHtml();
            const wrap = document.createElement('div');
            wrap.innerHTML = html;
            wrap.style.cssText = 'position:absolute;left:-9999px;top:0;width:210mm;background:#fff';
            document.body.appendChild(wrap);

            const doPdf = () => {
                const fn = (typeof html2pdf !== 'undefined' ? html2pdf : null) || window.html2pdf;
                if (fn) {
                    fn().set({
                        margin: 10,
                        filename: '專題指導申請單.pdf',
                        image: { type: 'jpeg', quality: 0.98 },
                        html2canvas: { scale: 2, useCORS: true },
                        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                    }).from(wrap.firstElementChild || wrap).save().then(() => {
                        document.body.removeChild(wrap);
                    }).catch(err => {
                        document.body.removeChild(wrap);
                        console.error(err);
                        Swal.fire({ icon: 'info', title: '請使用瀏覽器列印', text: '按 Ctrl+P 或 Cmd+P 可列印並存為 PDF' });
                    });
                } else {
                    document.body.removeChild(wrap);
                    const w = window.open('', '_blank');
                    if (w) {
                        w.document.write('<html><head><meta charset="utf-8"></head><body>' + html + '</body></html>');
                        w.document.close();
                        w.focus();
                        setTimeout(() => { w.print(); w.close(); }, 250);
                    } else {
                        Swal.fire({ icon: 'info', title: '請允許彈出視窗', text: '或按 Ctrl+P 列印並存為 PDF' });
                    }
                }
            };
            if (typeof html2pdf === 'undefined' && !window.html2pdf) {
                const s = document.createElement('script');
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
                s.onload = () => { setTimeout(doPdf, 100); };
                s.onerror = () => { document.body.removeChild(wrap); doPdf(); };
                document.head.appendChild(s);
            } else {
                doPdf();
            }
        },
        downloadWord() {
            const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>專題指導申請單</title></head><body style="font-family:Microsoft JhengHei;padding:20px">${this.getExportHtml()}</body></html>`;
            try {
                const blob = new Blob(['\ufeff' + html], { type: 'application/msword' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = '專題指導申請單.doc';
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
            } catch (e) {
                console.error(e);
                Swal.fire({ icon: 'error', title: '下載失敗', text: e.message });
            }
        },
        async submitForm() {
            if (this.isSubmitting) return;

            // 1. 依後台欄位設定做前端驗證
            if (this.fieldShow('tap_name') && this.fieldRequire('tap_name') && !this.form.project_name) {
                return Swal.fire({ icon: 'warning', title: '請填寫專題名稱' });
            }
            if (this.fieldShow('tap_teacher') && this.fieldRequire('tap_teacher') && !this.form.teacher_id) {
                return Swal.fire({ icon: 'warning', title: '請選擇指導老師' });
            }
            if (this.fieldShow('tap_teacher_2') && this.fieldRequire('tap_teacher_2') && !this.form.teacher_id_2) {
                return Swal.fire({ icon: 'warning', title: '請選擇指導老師-2' });
            }
            if (this.fieldShow('tap_teacher_3') && this.fieldRequire('tap_teacher_3') && !this.form.teacher_id_3) {
                return Swal.fire({ icon: 'warning', title: '請選擇指導老師-3' });
            }
            if (this.fieldShow('tap_group') && this.fieldRequire('tap_group') && !this.form.group_id) {
                return Swal.fire({ icon: 'warning', title: '請選擇類組' });
            }
            if (this.fieldShow('tap_url') && this.fieldRequire('tap_url') && !this.imagePreviewUrl && !this.imageFile) {
                return Swal.fire({ icon: 'warning', title: '請上傳照片' });
            }

            if (this.fieldShow('tap_member')) {
                const totalMembers = this.form.members.length + 1; // +1: 申請人本人
                const minMember = Number(this.applyConfig?.min_member || 1);
                const maxMember = Number(this.applyConfig?.max_member || 4);
                if (totalMembers < minMember || totalMembers > maxMember) {
                    return Swal.fire({ icon: 'warning', title: `組員數量需介於 ${minMember} 到 ${maxMember} 人` });
                }
            }

            // (已移除 "確定提交?" 的確認視窗，直接往下執行)

            this.isSubmitting = true;
            try {
                const fd = new FormData();
                fd.append('project_name', this.form.project_name);
                fd.append('teacher_id', this.form.teacher_id);
                fd.append('teacher_id_2', this.form.teacher_id_2 || '');
                fd.append('teacher_id_3', this.form.teacher_id_3 || '');
                fd.append('co_teacher_id', this.form.co_teacher_id);
                fd.append('group_id', this.form.group_id);
                fd.append('comment', this.form.comment);
                fd.append('member_ids', JSON.stringify(this.form.members.map(m => m.u_ID)));
                if (this.imageFile) fd.append('apply_image', this.imageFile);

                const res = await fetch(`${this.config.apiPath}?do=submit_application`, { method: 'POST', body: fd });
                const data = await res.json();

                if (data.ok) {
                    // --- 修改開始：使用右下角 Toast 提示 ---
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'bottom-end', // 右下角
                        showConfirmButton: false,
                        timer: 2000,            // 顯示 2 秒
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.onmouseenter = Swal.stopTimer;
                            toast.onmouseleave = Swal.resumeTimer;
                        }
                    });

                    Toast.fire({
                        icon: 'success',
                        title: '提交成功！'
                    });

                    // 延遲 1.5 秒後重新整理，讓使用者看得到提示
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                    // --- 修改結束 ---

                } else {
                    Swal.fire({ icon: 'error', title: '失敗', text: data.msg });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: '系統錯誤', text: e.message });
            } finally {
                this.isSubmitting = false;
            }
        },
        resetForm() {
            this.form.project_name = '';
            this.form.teacher_id = '';
            this.form.teacher_id_2 = '';
            this.form.teacher_id_3 = '';
            this.form.co_teacher_id = '';
            this.form.group_id = '';
            this.form.members = [];
            this.form.comment = '';
            this.removeImage();
        }
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // 檢查是否有掛載點
    if (document.getElementById('teamApplyApp')) {
        app.mount('#teamApplyApp');
    }
});