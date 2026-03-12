// js/meeting.js - 修正版


(function () {
    console.log("meeting.js 正在載入...");

    const Toast = window.Swal ? Swal.mixin({
        toast: true,
        position: 'bottom-end',
        showConfirmButton: false,
        timer: 6000,
        timerProgressBar: true
    }) : null;
    function toast(icon, title, opts) {
        if (Toast) {
            const opt = { icon, title, ...opts };
            if (icon === 'error' || icon === 'warning') opt.timer = opt.timer ?? 8000;
            else opt.timer = opt.timer ?? 6000;
            Toast.fire(opt);
        } else alert(title);
    }
    async function confirmSwal(title, text) {
        if (!window.Swal) return confirm(text || title);
        const result = await Swal.fire({
            icon: 'question',
            title: title,
            text: text,
            showCancelButton: true,
            confirmButtonText: '確定',
            cancelButtonText: '取消',
            reverseButtons: true
        });
        return !!result.isConfirmed;
    }

    function getTeamIdFromLocation() {
        // 1) 先吃後端注入（若有）
        const injected = Number(window.MEETING_TEAM || 0);
        if (Number.isFinite(injected) && injected > 0) return injected;

        // 2) main.php#pages/meeting.php?team_ID=...
        try {
            const hash = window.location.hash || '';
            const qpos = hash.indexOf('?');
            if (qpos >= 0) {
                const qs = new URLSearchParams(hash.slice(qpos + 1));
                const hTeam = Number(qs.get('team_ID') || 0);
                if (Number.isFinite(hTeam) && hTeam > 0) return hTeam;
            }
        } catch (_) { }

        // 3) /meeting.php?team_ID=...
        try {
            const qs = new URLSearchParams(window.location.search || '');
            const qTeam = Number(qs.get('team_ID') || 0);
            if (Number.isFinite(qTeam) && qTeam > 0) return qTeam;
        } catch (_) { }

        return 0;
    }

    async function callEditLock(lock, mrId, kind) {
        try {
            const body = { do: 'meeting_edit_lock', m_ID: currentMeetingID, lock, team_ID: CURRENT_TEAM_ID };
            if (mrId) body.mr_ID = mrId;
            if (kind) body.kind = kind;
            const res = await fetch(API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            const data = await res.json();
            return data;
        } catch (e) { return { ok: false, msg: '網路錯誤' }; }
    }

    let editStatusPollTimer = null;
    let myEditLockRecord = false;
    let myEditLockSummary = false;
    let lastSomeoneEditingToastAt = 0;
    let someoneEditingForConfirm = false;
    const SOMEONE_EDITING_TOAST_COOLDOWN_MS = 60000;
    let confirmWhenIdleTimer = null;

    async function startConfirmWhenIdle() {
        if (confirmWhenIdleTimer) return;
        setBusy(false);
        toast('info', '等待對方完成編輯後將自動確認會議…');
        const check = async () => {
            if (!currentMeetingID) return;
            try {
                const res = await fetch(`${API_URL}?do=meeting_edit_status&m_ID=${currentMeetingID}&team_ID=${CURRENT_TEAM_ID || ''}`);
                const data = await res.json();
                if (!data.ok) return;
                if (data.meeting_confirmed) {
                    if (confirmWhenIdleTimer) clearInterval(confirmWhenIdleTimer);
                    confirmWhenIdleTimer = null;
                    meetingLockedRaw = true;
                    applyMeetingLockState(true, false);
                    toast('success', '會議已確認');
                    return;
                }
                if (!data.someone_editing) {
                    if (confirmWhenIdleTimer) clearInterval(confirmWhenIdleTimer);
                    confirmWhenIdleTimer = null;
                    confirmMeeting();
                }
            } catch (_) {}
        };
        confirmWhenIdleTimer = setInterval(check, 3000);
        check();
    }

    async function pollEditStatus() {
        if (!currentMeetingID) return;
        try {
            const res = await fetch(`${API_URL}?do=meeting_edit_status&m_ID=${currentMeetingID}&team_ID=${CURRENT_TEAM_ID || ''}`);
            const data = await res.json();
            if (!data.ok) return;
            if (data.meeting_confirmed) {
                meetingLockedRaw = true;
                meetingReopened = Boolean(data.reopened);
                const canEdit = Boolean(data.can_edit);
                applyMeetingLockState(true, canEdit);
                if (!canEdit && !meetingLocked) {
                    if (Toast) Toast.fire({ icon: 'info', title: '指導老師已確認此次會議，內容已鎖定' });
                }
                return;
            }
            const isSelfLock = data.record_editing && (data.record_editing.by_user || '') === (window.MEETING_USER?.u_ID || '');
            const someoneElse = data.someone_editing && !myEditLockRecord && !myEditLockSummary && !isSelfLock;
            someoneEditingForConfirm = data.someone_editing || false;
            if (elements.btnConfirmMeeting && !meetingLockedRaw) {
                const disableByEditing = someoneEditingForConfirm;
                elements.btnConfirmMeeting.disabled = disableByEditing;
                elements.btnConfirmMeeting.innerHTML = disableByEditing ? `有人編輯中` : `確認此次會議`;
                elements.btnConfirmMeeting.title = disableByEditing ? '目前有人正在編輯會議內容，請等待完成後再確認' : '確認此次會議';
            }
            if (someoneElse) {
                const now = Date.now();
                if (now - lastSomeoneEditingToastAt < SOMEONE_EDITING_TOAST_COOLDOWN_MS) return;
                lastSomeoneEditingToastAt = now;
                const msg = data.ai_editing ? '有人正在編輯 AI 統整內容，請稍後再試' : '有人正在編輯會議紀錄，請稍後再試';
                if (Toast) Toast.fire({ icon: 'warning', title: msg });
                exitEditModeIfActive();
            }
        } catch (_) {}
    }

    function exitEditModeIfActive() {
        if (!elements.meetingText) return;
        const root = elements.meetingText;
        if (root.classList.contains('is-editing-record')) {
            root.classList.remove('is-editing-record');
            const btn = root.querySelector('.content-edit-toggle-btn');
            const nameEl = root.querySelector('.content-filemeta-name');
            const contentEl = root.querySelector('.content-kind-item-text, .content-note-body');
            if (btn) btn.textContent = '編輯';
            if (nameEl) nameEl.removeAttribute('contenteditable');
            if (contentEl) contentEl.removeAttribute('contenteditable');
            myEditLockRecord = false;
            callEditLock(0, getCurrentMrId(), null);
        }
        if (root.classList.contains('is-editing-summary')) {
            root.classList.remove('is-editing-summary');
            const inner = root.querySelector('.content-summary-inner');
            if (inner) inner.setAttribute('contenteditable', 'false');
            myEditLockSummary = false;
            callEditLock(0, null, 'summary');
        }
        if (root.classList.contains('is-editing-record-content')) {
            root.classList.remove('is-editing-record-content');
            const contentEl = root.querySelector('.content-kind-item-text[data-mr-id], .content-transcript-segments[data-mr-id]');
            const mrId = contentEl ? parseInt(contentEl.getAttribute('data-mr-id') || '0', 10) : 0;
            if (mrId) callEditLock(0, mrId, null);
            const btn = root.querySelector('.content-edit-toggle-btn[data-mr-id]');
            if (btn) btn.textContent = '編輯';
            if (contentEl) contentEl.setAttribute('contenteditable', 'false');
            contentEl?.querySelectorAll('.segment-text').forEach(el => el.setAttribute('contenteditable', 'false'));
        }
    }

    function getCurrentMrId() {
        const el = elements.meetingText?.querySelector('.content-note-body, .content-kind-item-text[data-mr-id]');
        const id = el?.getAttribute('data-mr-id');
        return id ? parseInt(id, 10) : 0;
    }

    function startEditStatusPolling() {
        if (editStatusPollTimer) clearInterval(editStatusPollTimer);
        editStatusPollTimer = setInterval(pollEditStatus, 4000);
    }

    function stopEditStatusPolling() {
        if (editStatusPollTimer) {
            clearInterval(editStatusPollTimer);
            editStatusPollTimer = null;
        }
    }

    let newMeetingPollTimer = null;
    let lastKnownLatestMid = 0;

    async function pollNewMeeting() {
        if (!CURRENT_TEAM_ID) return;
        try {
            const qs = new URLSearchParams({ do: 'meeting_check_new' });
            if (CURRENT_TEAM_ID > 0) qs.set('team_ID', String(CURRENT_TEAM_ID));
            const res = await fetch(`${API_URL}?${qs.toString()}`, { cache: 'no-store' });
            const data = await res.json();
            if (!data.ok) return;
            const latest = (data.latest_m_ID || 0) | 0;
            const myCurrent = (currentMeetingID || 0) | 0;
            if (latest > myCurrent && latest > lastKnownLatestMid) {
                if (Toast) Toast.fire({ icon: 'info', title: '有新的會議，請重整頁面以查看', timer: 6000 });
                else alert('有新的會議，請重整頁面以查看');
            }
            lastKnownLatestMid = latest;
        } catch (_) {}
    }

    function startNewMeetingPolling() {
        if (newMeetingPollTimer) clearInterval(newMeetingPollTimer);
        lastKnownLatestMid = (currentMeetingID || 0) | 0;
        newMeetingPollTimer = setInterval(pollNewMeeting, 25000);
    }

    function stopNewMeetingPolling() {
        if (newMeetingPollTimer) {
            clearInterval(newMeetingPollTimer);
            newMeetingPollTimer = null;
        }
    }

    async function toggleSummaryEditMode() {
        if (!elements.meetingText) return;
        const root = elements.meetingText;
        const isEditing = root.classList.toggle('is-editing-summary');
        const editBtn = root.querySelector('.content-edit-toggle-btn[data-kind="summary"]');
        const saveBtn = root.querySelector('.content-save-summary-btn');
        const inner = root.querySelector('.content-summary-inner');
        if (!inner || !editBtn || !saveBtn) return;
        if (isEditing) {
            const r = await callEditLock(1, null, 'summary');
            if (!r.ok) {
                root.classList.remove('is-editing-summary');
                toast('warning', r.msg || '無法進入編輯模式');
                return;
            }
            myEditLockSummary = true;
            inner.setAttribute('contenteditable', 'true');
            inner.querySelectorAll('.content-summary-text, .content-summary-points').forEach(el => el.setAttribute('contenteditable', 'true'));
            editBtn.style.display = 'none';
            saveBtn.style.display = 'inline-flex';
        } else {
            myEditLockSummary = false;
            callEditLock(0, null, 'summary');
            inner.setAttribute('contenteditable', 'false');
            inner.querySelectorAll('.content-summary-text, .content-summary-points').forEach(el => el.setAttribute('contenteditable', 'false'));
            editBtn.style.display = 'inline-flex';
            saveBtn.style.display = 'none';
        }
    }

    async function saveSummaryContent() {
        if (!elements.meetingText || !currentMeetingID) return;
        const inner = elements.meetingText.querySelector('.content-summary-inner');
        if (!inner) return;
        const summaryEl = inner.querySelector('.content-summary-text, .content-summary-empty');
        const pointsEl = inner.querySelector('.content-summary-points');
        const summary = summaryEl ? summaryEl.innerText.trim() : '';
        const points = pointsEl ? pointsEl.innerText.trim().replace(/<br\s*\/?>/gi, '\n') : '';
        setBusy(true, '完成儲存中...', '');
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    do: 'meeting_save_summary',
                    m_ID: currentMeetingID,
                    team_ID: CURRENT_TEAM_ID,
                    summary,
                    points
                })
            });
            const data = await res.json();
            if (!data.ok) {
                toast('error', data.msg || '儲存失敗');
                return;
            }
            myEditLockSummary = false;
            elements.meetingText.classList.remove('is-editing-summary');
            const editBtn = elements.meetingText.querySelector('.content-edit-toggle-btn[data-kind="summary"]');
            const saveBtn = elements.meetingText.querySelector('.content-save-summary-btn');
            if (editBtn) editBtn.style.display = 'inline-flex';
            if (saveBtn) saveBtn.style.display = 'none';
            inner.setAttribute('contenteditable', 'false');
            inner.querySelectorAll('.content-summary-text, .content-summary-points').forEach(el => el.setAttribute('contenteditable', 'false'));
            callEditLock(0, null, 'summary');
            await refreshContentTypeCounts(currentMeetingID);
            renderContentKindPanel('summary');
            toast('success', '已完成');
        } catch (e) {
            toast('error', '儲存失敗');
        } finally {
            setBusy(false);
        }
    }

    async function toggleRecordContentEditMode(mrId, kind) {
        if (!elements.meetingText || !currentMeetingID || !mrId) return;
        const root = elements.meetingText;
        const contentEl = root.querySelector('.content-kind-item-text, .content-transcript-segments');
        const btn = root.querySelector(`.content-edit-toggle-btn[data-kind="${kind}"][data-mr-id="${String(mrId)}"]`);
        if (!contentEl || !btn) return;
        const isEditing = root.classList.toggle('is-editing-record-content');
        if (isEditing) {
            const r = await callEditLock(1, mrId, null);
            if (!r.ok) {
                root.classList.remove('is-editing-record-content');
                toast('warning', r.msg || '無法進入編輯模式');
                return;
            }
            contentEl.setAttribute('contenteditable', 'true');
            if (contentEl.classList.contains('content-transcript-segments')) {
                contentEl.querySelectorAll('.segment-text').forEach(el => el.setAttribute('contenteditable', 'true'));
            }
            btn.textContent = '完成';
            contentEl.focus();
        } else {
            const content = contentEl.classList.contains('content-transcript-segments')
                ? Array.from(contentEl.querySelectorAll('.segment-text')).map(el => el.innerText).join('\n')
                : contentEl.innerText || '';
            setBusy(true, '完成儲存中...', '');
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ do: 'meeting_save_record', m_ID: currentMeetingID, team_ID: CURRENT_TEAM_ID, mr_ID: mrId, content })
                });
                const data = await res.json();
                if (!data.ok) {
                    toast('error', data.msg || '儲存失敗');
                    root.classList.add('is-editing-record-content');
                    return;
                }
                await callEditLock(0, mrId, null);
                contentEl.setAttribute('contenteditable', 'false');
                if (contentEl.classList.contains('content-transcript-segments')) {
                    contentEl.querySelectorAll('.segment-text').forEach(el => el.setAttribute('contenteditable', 'false'));
                }
                btn.textContent = '編輯';
                await refreshContentTypeCounts(currentMeetingID);
                await openMeetingFiles(currentMeetingID);
                renderContentKindPanel(kind);
                toast('success', '已完成');
            } catch (e) {
                toast('error', '儲存失敗');
                root.classList.add('is-editing-record-content');
            } finally {
                setBusy(false);
            }
        }
    }

    function toggleRecordEditMode() {
        if (!elements.meetingText) return;
        const root = elements.meetingText;
        const isEditing = root.classList.toggle('is-editing-record');
        const nameEl = root.querySelector('.content-filemeta-name');
        const contentEl = root.querySelector('.content-kind-item-text, .content-note-body');
        const btn = root.querySelector('.content-edit-toggle-btn');
        if (!contentEl || !btn) return;
        if (isEditing) {
            (async () => {
                const mrId = getCurrentMrId();
                const r = await callEditLock(1, mrId || undefined, null);
                if (!r.ok) {
                    root.classList.remove('is-editing-record');
                    toast('warning', r.msg || '無法進入編輯模式');
                    return;
                }
                myEditLockRecord = true;
                btn.textContent = '完成';
                if (nameEl) nameEl.setAttribute('contenteditable', 'true');
                contentEl.setAttribute('contenteditable', 'true');
                contentEl.focus();
            })();
        } else {
            (async () => {
                noteDraftCache = contentEl ? contentEl.innerText : '';
                noteDraftName = nameEl ? (nameEl.innerText.trim() || '打字紀錄') : noteDraftName;
                const content = (noteDraftCache || '').trim();
                if (content && currentMeetingID) {
                    setBusy(true, '儲存中...', '');
                    try {
                        await window.saveMeetingNote();
                    } catch (e) {
                        toast('error', '儲存失敗');
                    } finally {
                        setBusy(false);
                    }
                }
                myEditLockRecord = false;
                await callEditLock(0, getCurrentMrId(), null);
                btn.textContent = '編輯';
                if (nameEl) nameEl.setAttribute('contenteditable', 'false');
                contentEl.setAttribute('contenteditable', 'false');
                setUnsavedContentState(false);
            })();
        }
    }

    // 1. 路徑修正：因為你的網址是 main.php，所以 API 就在 modules/ 資料夾
    // 若還有問題，建議直接寫死絕對路徑 '/projecteverydays/modules/meeting_api.php'
    const API_URL = (() => {
        if (window.MEETING_API) return window.MEETING_API;
        const path = window.location.pathname || '';
        const base = path.replace(/\/[^/]*$/, '/');
        return base + 'modules/meeting_api.php';
    })();
    const CURRENT_TEAM_ID = getTeamIdFromLocation();
    const PROJECT_BASE = (() => {
        const api = String(window.MEETING_API || '').trim();
        if (!api) return '';
        const idx = api.lastIndexOf('/modules/');
        return idx > 0 ? api.slice(0, idx) : '';
    })();

    const elements = {
        meetingTitle: document.getElementById('meetingTitle'),

        meetingHistory: document.getElementById('meetingHistory'),
        textInput: document.getElementById('textInput'),
        uploadTextZone: document.getElementById('uploadTextZone'),

        meetingFiles: document.getElementById('meetingFiles'),
        btnHistoryBack: document.getElementById('btnHistoryBack'),
        historyTitle: document.getElementById('historyTitle'),
        recordBtn: document.getElementById('recordBtn'),
        stopRecordBtn: document.getElementById('stopRecordBtn'),
        recordingStatus: document.getElementById('recordingStatus'),
        recordingTime: document.getElementById('recordingTime'),
        imageInput: document.getElementById('imageInput'),
        uploadImageZone: document.getElementById('uploadImageZone'),
        audioInput: document.getElementById('audioInput'),
        uploadAudioZone: document.getElementById('uploadAudioZone'),
        uploadedFiles: document.getElementById('uploadedFiles'),
        meetingText: document.getElementById('meetingText'),
        btnSummarize: document.getElementById('btnSummarize'),
        btnClear: document.getElementById('btnClear'),
        btnDeleteMeeting: document.getElementById('btnDeleteMeeting'),
        createMeetingZone: document.getElementById('createMeetingZone'),
        btnCreateMeeting: document.getElementById('btnCreateMeeting'),
        createMeetingModal: document.getElementById('createMeetingModal'),
        newMeetingTitleInput: document.getElementById('newMeetingTitleInput'),
        newMeetingDateInput: document.getElementById('newMeetingDateInput'),
        createModalNoteText: document.getElementById('createModalNoteText'),
        createModalTextFiles: document.getElementById('createModalTextFiles'),
        createModalImageFiles: document.getElementById('createModalImageFiles'),
        createModalAudioFiles: document.getElementById('createModalAudioFiles'),
        createModalAiSummarize: document.getElementById('createModalAiSummarize'),
        btnCancelCreate: document.getElementById('btnCancelCreate'),
        btnConfirmCreate: document.getElementById('btnConfirmCreate'),
        appContainer: document.getElementById('appContainer'),
        historyKeyword: document.getElementById('historyKeyword'),
        historyDateFrom: document.getElementById('historyDateFrom'),
        historyDateTo: document.getElementById('historyDateTo'),
        toolToggle: document.getElementById('toolToggle'),
        toolBody: document.getElementById('toolBody'),
        toolChevron: document.getElementById('toolChevron'),
        attendanceStats: document.getElementById('attendanceStats'),
        btnConfirmMeeting: document.getElementById('btnConfirmMeeting'),
        btnReopenMeeting: document.getElementById('btnReopenMeeting'),

        attendanceList: document.getElementById('attendanceList'),
        btnCheckIn: document.getElementById('btnCheckIn'),
        dateBadgeStart: document.getElementById('dateBadgeStart'),
        dateBadgeCreated: document.getElementById('dateBadgeCreated')
    };
    let busySwalFallback = null;
    function setBusy(on, title = "處理中...", desc = "請稍候，不要關閉頁面") {
        const ov = document.getElementById('busyOverlay');
        const t = document.getElementById('busyTitle');
        const d = document.getElementById('busyDesc');
        if (ov) {
            if (t) t.innerText = title;
            if (d) d.innerText = desc;
            ov.classList.toggle('is-visible', on);
        } else if (window.Swal) {
            if (on) {
                busySwalFallback = Swal.fire({
                    title: title,
                    html: desc,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => { Swal.showLoading(); }
                });
            } else if (busySwalFallback) {
                Swal.close();
                busySwalFallback = null;
            }
        }

        // 鎖按鈕避免連點
        const lock = (el) => { if (el) el.style.pointerEvents = on ? 'none' : 'auto'; };
        lock(elements.btnSummarize);
        lock(elements.btnCheckIn);
        lock(elements.uploadImageZone);
        lock(elements.uploadAudioZone);
        lock(elements.recordBtn);
        lock(elements.btnConfirmMeeting);
    }

    let mediaRecorder;
    let audioChunks = [];
    let recordingTimer;
    let attendanceRefreshTimer = null;
    let startTime;
    let currentMeetingID = null;
    let viewingHistory = false;
    let meetingLocked = false;
    let meetingLockedRaw = false;  // 會議是否已確認（不論是否開放修改）
    let meetingReopened = false;   // 指導老師是否已開放修改
    const IS_TEACHER = Boolean(window.MEETING_USER?.is_teacher);

    /** @param {boolean} locked - 會議是否已確認
     *  @param {boolean} [can_edit] - 當前使用者是否可編輯（已開放修改時，組員與老師可編輯）
     *  @param {string} [msg] - 鎖定時的提示訊息 */
    function applyMeetingLockState(locked, can_edit, msg = '指導老師已確認此次會議，內容已鎖定') {
        const effectiveLocked = Boolean(locked) && (can_edit === undefined ? true : !Boolean(can_edit));
        meetingLocked = effectiveLocked;

        if (elements.meetingText) {
            elements.meetingText.setAttribute('contenteditable', 'false');
            const noteBody = elements.meetingText.querySelector('.content-note-body');
            if (noteBody) noteBody.setAttribute('contenteditable', meetingLocked ? 'false' : 'true');
        }
        if (elements.meetingTitle) {
            elements.meetingTitle.setAttribute('contenteditable', meetingLocked ? 'false' : 'true');
        }

        const disable = (el, on) => {
            if (!el) return;
            el.style.pointerEvents = on ? 'none' : 'auto';
            el.style.opacity = on ? '0.55' : '1';
        };

        // 會議鎖定時：仍允許「上傳文字檔 / 圖片 / 音檔 / 錄音」，
        // 但鎖住出席、AI 統整、清除、刪除與確認相關操作
        disable(elements.btnSummarize, meetingLocked);
        disable(elements.btnClear, meetingLocked);
        disable(elements.btnDeleteMeeting, meetingLocked);
        disable(elements.btnCheckIn, meetingLocked);

        if (elements.btnConfirmMeeting) {
            if (meetingLocked) {
                elements.btnConfirmMeeting.disabled = true;
                elements.btnConfirmMeeting.innerHTML = `已確認`;
                elements.btnConfirmMeeting.title = msg;
            } else {
                const disableByEditing = someoneEditingForConfirm;
                elements.btnConfirmMeeting.disabled = disableByEditing;
                elements.btnConfirmMeeting.innerHTML = disableByEditing ? `有人編輯中` : `確認此次會議`;
                elements.btnConfirmMeeting.title = disableByEditing ? '目前有人正在編輯會議內容，請等待完成後再確認' : '確認此次會議';
            }
        }
        updateReopenButtonUI();
    }

    function updateReopenButtonUI() {
        const btn = elements.btnReopenMeeting;
        if (!btn) return;
        const show = meetingLockedRaw && !meetingReopened && IS_TEACHER;
        btn.style.display = show ? '' : 'none';
        btn.disabled = !show;
    }

    function ensureMeetingEditable(kindOverride) {
        const k = kindOverride || currentContentKind;
        // 會議鎖定時仍允許「文字檔 / 圖片 / 音檔」上傳與內容調整，
        // 但禁止打字紀錄、AI 摘要與出席相關修改
        if (meetingLocked && (k === 'note' || k === 'summary')) {
            toast('warning', '此會議已確認，無法再修改。');
            return false;
        }
        return true;
    }

    // ======= History: list + filters + open on right =======

    let historyView = {
        m_ID: null,
        m_title: '',
        filesByKind: {},
        selectedKind: 'note'
    };
    let currentFileIndexByKind = {}; // 各類型目前顯示的檔案索引（0-based）
    let openFilesRequestSeq = 0;
    let currentContentKind = 'note';
    let noteDraftCache = '';
    let noteDraftName = '打字紀錄';
    let noteEditor = '';
    let noteUpdatedTime = '';
    let currentMeetingFilesByKind = { note: [], text: [], image: [], audio: [], summary: [] };
    let currentContentCounts = { note: 0, text: 0, image: 0, audio: 0, summary: 0 };
    let hasUnsavedContent = false;
    let pendingClear = {}; // { kind: true } 本地暫存清除，尚未儲存
    function setUnsavedContentState(dirty) {
        hasUnsavedContent = Boolean(dirty);
        const statusEl = document.getElementById('contentSaveStatus');
        if (!statusEl) return;
        statusEl.textContent = hasUnsavedContent ? '未儲存變更' : '草稿（已儲存）';
        statusEl.style.color = hasUnsavedContent ? '#b91c1c' : '#166534';
    }

    function bindBeforeUnloadGuard() {
        if (window.__meeting_beforeunload_bound) return;
        window.__meeting_beforeunload_bound = true;
        window.addEventListener('beforeunload', (event) => {
            if (!hasUnsavedContent) return;
            event.preventDefault();
            event.returnValue = '';
        });
    }

    function updateNoteEmptyHint() {
        const hint = document.getElementById('noteEmptyHint');
        if (!hint) return;
        const show = currentContentKind === 'note' && Number(currentContentCounts.note || 0) === 0;
        hint.classList.toggle('is-show', show);
    }

    async function loadMeetingHistory() {
        if (!elements.meetingHistory) return;

        const kw = (elements.historyKeyword?.value || '').trim();
        const from = elements.historyDateFrom?.value || '';
        const to = elements.historyDateTo?.value || '';

        const qs = new URLSearchParams();
        qs.set('do', 'meeting_list');
        if (CURRENT_TEAM_ID > 0) qs.set('team_ID', String(CURRENT_TEAM_ID));
        if (kw) qs.set('kw', kw);
        if (from) qs.set('from', from);
        if (to) qs.set('to', to);

        elements.meetingHistory.innerHTML = `<div class="empty-state" style="padding:16px; color:#64748b; font-size:14px;">載入中...</div>`;

        try {
            const res = await fetch(`${API_URL}?${qs.toString()}`, { method: 'GET' });
            const raw = await res.text();

            if (raw.trim().startsWith('<')) {
                console.error('meeting_list 回傳 HTML（PHP 錯誤）:', raw);
                elements.meetingHistory.innerHTML = `<div style="padding:10px; color:#ef4444; font-size:13px;">載入失敗（伺服器錯誤）</div>`;
                return;
            }

            const data = JSON.parse(raw);
            if (!data.ok) {
                elements.meetingHistory.innerHTML = `<div style="padding:10px; color:#ef4444; font-size:13px;">${escapeHtml(data.msg || '載入失敗')}</div>`;
                return;
            }

            const list = data.list || [];
            if (!list.length) {
                elements.meetingHistory.innerHTML = `<div class="empty-state" style="padding:24px 16px; color:#64748b; font-size:14px;">找不到符合條件的記錄</div>`;
                return;
            }

            // 依月份分組，做時間軸視覺（使用 m_created_display 含會議時間 m_start_d）
            const groups = {};
            list.forEach((m, idx) => {
                const created = String(m.m_created_display || m.m_created_d || '').trim();
                const ymMatch = created.match(/^(\d{4})[\/\-](\d{2})/);
                const key = ymMatch ? `${ymMatch[1]}-${ymMatch[2]}` : '其他';
                if (!groups[key]) groups[key] = [];
                groups[key].push({ ...m, __isLatest: idx === 0 });
            });

            const monthKeys = Object.keys(groups).sort((a, b) => b.localeCompare(a));
            elements.meetingHistory.innerHTML = monthKeys.map(key => {
                let monthLabel = '其他';
                if (key !== '其他') {
                    const y = Number(key.slice(0, 4));
                    const m = Number(key.slice(5, 7));
                    const now = new Date();
                    const curY = now.getFullYear();
                    const curM = now.getMonth() + 1;
                    const prev = new Date(curY, curM - 2, 1);
                    const prevY = prev.getFullYear();
                    const prevM = prev.getMonth() + 1;

                    if (y === curY && m === curM) monthLabel = '本月';
                    else if (y === prevY && m === prevM) monthLabel = '上月';
                    else monthLabel = `${y} 年 ${m} 月`;
                }
                const itemsHtml = (groups[key] || []).map(m => {
                    const created = String(m.m_created_display || m.m_created_d || '').trim();
                    const parts = created.split(' ');
                    const datePart = parts[0] || '';
                    const timePart = parts[1] || '';
                    return `
                    <article class="history-timeline-item" data-mid="${m.m_ID}">
                      <div class="history-item-head">
                        <div class="history-item-title">${escapeHtml(m.m_title || '未命名會議')}</div>
                        ${m.__isLatest ? '<span class="history-badge-latest">最新</span>' : ''}
                      </div>
                      <div class="history-item-time">${escapeHtml(datePart)} ${escapeHtml(timePart)}</div>
                    </article>
                    `;
                }).join('');

                return `
                <section class="history-month-group">
                  <div class="history-month-title">${escapeHtml(monthLabel)}</div>
                  <div class="history-month-items">${itemsHtml}</div>
                </section>
                `;
            }).join('');

            // ✅ 點 item -> 右側載入該 meeting 的檔案
            elements.meetingHistory.querySelectorAll('.history-timeline-item').forEach(el => {
                el.addEventListener('click', () => {
                    const mid = el.getAttribute('data-mid');
                    openMeetingFiles(mid);
                });
            });

        } catch (e) {
            console.error('loadMeetingHistory error:', e);
            elements.meetingHistory.innerHTML = `<div style="padding:10px; color:#ef4444; font-size:13px;">載入失敗（網路或程式錯誤）</div>`;
        }
    }

    function bindHistoryFilters() {
        const kwEl = elements.historyKeyword || document.getElementById('historyKeyword');
        const fromEl = elements.historyDateFrom || document.getElementById('historyDateFrom');
        const toEl = elements.historyDateTo || document.getElementById('historyDateTo');

        if (!kwEl && !fromEl && !toEl) return;

        const debounce = (fn, delay = 250) => {
            let t;
            return (...args) => {
                clearTimeout(t);
                t = setTimeout(() => fn(...args), delay);
            };
        };

        const normalizeDatesAndReload = () => {
            const fromVal = fromEl?.value || '';
            const toVal = toEl?.value || '';
            if (fromVal && toVal && fromVal > toVal) {
                const tmp = fromEl.value;
                fromEl.value = toEl.value;
                toEl.value = tmp;
            }
            loadMeetingHistory();
        };

        const reload = debounce(normalizeDatesAndReload, 250);

        // SPA 重複載入保護：避免事件越綁越多
        const safeBind = (el, eventName, handler) => {
            if (!el) return;
            const key = `__mh_${eventName}`;
            if (el[key]) el.removeEventListener(eventName, el[key]);
            el[key] = handler;
            el.addEventListener(eventName, handler);
        };

        safeBind(kwEl, 'input', reload);
        safeBind(fromEl, 'change', reload);
        safeBind(toEl, 'change', reload);

        // 需要的話預設近 30 天（不要就整段註解）
        if (fromEl && toEl && !fromEl.value && !toEl.value) {
            const today = new Date();
            const toStr = today.toISOString().slice(0, 10);
            const past = new Date(today);
            past.setDate(today.getDate() - 30);
            const fromStr = past.toISOString().slice(0, 10);
            fromEl.value = fromStr;
            toEl.value = toStr;
        }
    }

    // ======= Right panel: files selector + multi-select + pager =======

    async function openMeetingFiles(m_ID) {
        const mid = parseInt(m_ID, 10);
        if (!mid) return;
        currentMeetingID = mid;
        lastKnownLatestMid = mid;
        const requestSeq = ++openFilesRequestSeq;

        try {
            await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ do: 'meeting_clear_my_locks', m_ID: mid, team_ID: CURRENT_TEAM_ID || undefined })
            });
        } catch (_) {}

        setBusy(true, "載入歷史會議...", "正在取得檔案清單");
        try {
            const qs = new URLSearchParams({ do: 'meeting_files', id: String(mid) });
            if (CURRENT_TEAM_ID > 0) qs.set('team_ID', String(CURRENT_TEAM_ID));
            const res = await fetch(`${API_URL}?${qs.toString()}`, { method: 'GET', cache: 'no-store' });
            const raw = await res.text();
            if (requestSeq !== openFilesRequestSeq) return;
            if (raw.trim().startsWith('<')) {
                console.error('meeting_files 回傳 HTML（PHP 錯誤）:', raw);
                toast('error', 'meeting_files 伺服器錯誤，請看 Console');
                return;
            }
            const data = JSON.parse(raw);
            if (requestSeq !== openFilesRequestSeq) return;
            if (!data.ok) {
                toast('error', data.msg || '載入檔案失敗');
                return;
            }

            const files = data.files || [];
            const filesByKind = {
                note: files.filter(f => f.kind === 'note'),
                text: files.filter(f => f.kind === 'text'),
                image: files.filter(f => f.kind === 'image'),
                audio: files.filter(f => f.kind === 'audio'),
                summary: files.filter(f => f.kind === 'summary')
            };

            historyView = {
                m_ID: mid,
                m_title: data.m_title || `會議 #${mid}`,
                filesByKind,
                selectedKind: 'note' // 預設先顯示手打文字
            };
            currentFileIndexByKind = {}; // 切換會議時重置頁碼
            if (elements.meetingTitle && historyView.m_title) {
                elements.meetingTitle.innerText = historyView.m_title;
            }
            updateDateBadges(data);
            // 改為沿用同一套右側樣式（不再插入第二層 history-view-panel）
            if (elements.meetingText) {
                elements.meetingText.classList.remove('is-history-mode');
            }
            currentMeetingFilesByKind = filesByKind;
            noteDraftCache = String((filesByKind.note?.[0]?.content) || '');
            noteDraftName = String((filesByKind.note?.[0]?.name) || '打字紀錄');
            await refreshContentTypeCounts(mid);
            await loadAttendance();
            startEditStatusPolling();
        } finally {
            setBusy(false);
        }
    }

    function renderRightFileSelector() {
        // 把右側 editor 變成「瀏覽模式」容器（仍在右側大區塊，不換頁）
        if (!elements.meetingText) return;
        elements.meetingText.classList.add('is-history-mode');

        elements.meetingText.setAttribute('contenteditable', 'false');
        elements.meetingText.innerHTML = `
    <section class="history-view-panel">
      <nav id="rightTypeTabs" class="history-type-tabs" aria-label="檔案類型"></nav>
      <section id="rightFileContent" class="history-file-content" aria-live="polite">
        <p class="history-file-content__loading">載入中...</p>
      </section>
    </section>
  `;

        const tabs = document.getElementById('rightTypeTabs');
        if (!tabs) return;
        const typeDefs = [
            { kind: 'note', label: '打字紀錄' },
            { kind: 'text', label: '文字檔' },
            { kind: 'image', label: '圖片 OCR' },
            { kind: 'audio', label: '語音轉錄' },
            { kind: 'summary', label: 'AI 摘要' }
        ];
        tabs.innerHTML = typeDefs.map(t => {
            const count = (historyView.filesByKind[t.kind] || []).length;
            const active = historyView.selectedKind === t.kind;
            const stateClass = `${active ? 'is-active' : ''} ${count === 0 ? 'is-empty' : ''}`.trim();
            return `
            <button class="history-type-btn ${stateClass}"
                    data-kind="${t.kind}" type="button">
                <span class="history-type-btn__label">${escapeHtml(t.label)}</span>
                <span class="history-type-btn__count">${count}</span>
            </button>
            `;
        }).join('');
        tabs.querySelectorAll('.history-type-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                historyView.selectedKind = btn.getAttribute('data-kind') || 'note';
                renderRightFileSelector();
                renderRightFileContent();
            });
        });
    }

    function renderRightFileContent() {
        const box = document.getElementById('rightFileContent');
        if (!box) return;
        const kind = historyView.selectedKind || 'note';
        const items = historyView.filesByKind?.[kind] || [];
        const kindLabel = {
            note: '打字紀錄',
            text: '文字檔',
            image: '圖片 OCR',
            audio: '語音轉錄',
            summary: 'AI 摘要'
        }[kind] || kind;

        if (!items.length) {
            const emptyCta = getEmptyStateAction(kind);
            const showUploadImageInEmpty = false;
            box.innerHTML = `
            <article class="history-empty">
                <div class="history-empty__icon"><i class="fa-regular fa-file-lines"></i></div>
                <h4 class="history-empty__title">尚無${escapeHtml(kindLabel)}</h4>
                <p class="history-empty__text">
                ${escapeHtml(emptyCta.hint)}
                </p>
                <div class="history-empty-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                    <button type="button" class="history-empty-cta" data-action="${escapeHtml(emptyCta.action)}">${escapeHtml(emptyCta.label)}</button>
                    ${showUploadImageInEmpty ? `<button type="button" class="content-upload-btn content-workspace-btn" data-action="upload-image"><i class="fa-solid fa-image"></i> 上傳圖片</button>` : ''}
                </div>
            </article>
            `;
            const ctaBtn = box.querySelector('.history-empty-cta');
            if (ctaBtn) {
                ctaBtn.addEventListener('click', () => handleEmptyStateAction(ctaBtn.getAttribute('data-action') || ''));
            }
            box.querySelectorAll('.content-upload-btn[data-action="upload-image"]').forEach(btn => {
                btn.addEventListener('click', () => elements.imageInput?.click());
            });
            return;
        }

        const idx = Math.min(currentFileIndexByKind[kind] || 0, items.length - 1);
        currentFileIndexByKind[kind] = idx;
        const item = items[idx];
        const content = normalizeLineBreaks(item.content || '');
        const contentPoints = normalizeLineBreaks(item.content_points || '');
        const hasSummary = content.trim().length > 0;
        const hasPoints = contentPoints.trim().length > 0;
        const showPager = items.length > 1;
        const prevDisabled = idx <= 0;
        const nextDisabled = idx >= items.length - 1;

        const contentHtml = kind === 'summary' && (hasSummary || hasPoints)
            ? `
            ${hasSummary ? `<section class="content-summary-section"><h4 class="content-summary-heading">AI 統整重點</h4><pre class="history-content-item__text">${escapeHtml(content)}</pre></section>` : ''}
            ${hasPoints ? `<section class="content-summary-section"><h4 class="content-summary-heading">AI 統整條列式</h4><pre class="history-content-item__text">${escapeHtml(contentPoints)}</pre></section>` : ''}
            `
            : `<pre class="history-content-item__text">${escapeHtml(content || contentPoints || '（此筆無文字內容）')}</pre>`;

        const historyActionsHtml = '';

        box.innerHTML = `
        <div class="content-viewer-wrap">
            ${showPager ? `
            <div class="content-pager">
                <button type="button" class="content-pager-btn" data-dir="prev" ${prevDisabled ? 'disabled' : ''} title="上一頁"><i class="fa-solid fa-chevron-left"></i></button>
                <span class="content-pager-info">${idx + 1} / ${items.length}</span>
                <button type="button" class="content-pager-btn" data-dir="next" ${nextDisabled ? 'disabled' : ''} title="下一頁"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            ` : ''}
            ${contentHtml}
            ${historyActionsHtml}
        </div>
        `;

        box.querySelectorAll('.content-upload-btn[data-action="upload-image"]').forEach(btn => {
            btn.addEventListener('click', () => elements.imageInput?.click());
        });

        if (showPager) {
            box.querySelectorAll('.content-pager-btn').forEach(btn => {
                if (btn.disabled) return;
                btn.addEventListener('click', () => {
                    const dir = btn.getAttribute('data-dir');
                    let newIdx = currentFileIndexByKind[kind] || 0;
                    if (dir === 'prev') newIdx = Math.max(0, newIdx - 1);
                    else if (dir === 'next') newIdx = Math.min(items.length - 1, newIdx + 1);
                    currentFileIndexByKind[kind] = newIdx;
                    renderRightFileContent();
                });
            });
        }
    }

    function getEmptyStateAction(kind) {
        const map = {
            note: {
                action: 'edit-note',
                label: '切回本次會議編輯',
                hint: '你可以先回到本次會議內容，開始手打紀錄。'
            },
            text: {
                action: 'upload-text',
                label: '上傳文字檔',
                hint: '你可以上傳文字檔，快速補齊這次會議內容。'
            },
            image: {
                action: 'upload-image',
                label: '上傳圖片 OCR',
                hint: '你可以上傳會議照片，系統會自動進行文字辨識。'
            },
            audio: {
                action: 'record-audio',
                label: '開始錄音或上傳音檔',
                hint: '你可以開始錄音，或上傳音檔產生語音轉錄內容。'
            },
            summary: {
                action: 'generate-summary',
                label: '產生 AI 摘要',
                hint: '先準備文字內容後，即可一鍵整理出會議摘要。'
            }
        };
        return map[kind] || {
            action: 'noop',
            label: '回到工作區',
            hint: '目前沒有這個類型的資料，可先新增內容。'
        };
    }

    function handleEmptyStateAction(action) {
        switch (action) {
            case 'edit-note':
                if (!ensureMeetingEditable('note')) return;
                historyView.selectedKind = 'note';
                viewingHistory = false;
                loadMeetingData();
                break;
            case 'upload-text':
                elements.textInput?.click();
                break;
            case 'upload-image':
                elements.imageInput?.click();
                break;
            case 'record-audio':
                elements.recordBtn?.click();
                break;
            case 'generate-summary':
                if (!ensureMeetingEditable('summary')) return;
                elements.aiBtn?.click();
                break;
            default:
                break;
        }
    }

    function fileIcon(kind) {
        return '';
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    // 顯示用檔名（去除路徑與副檔名）
    function getDisplayFileName(name) {
        const n = String(name || '').trim();
        if (!n) return '';
        const lastSlash = Math.max(n.lastIndexOf('/'), n.lastIndexOf('\\'));
        const base = lastSlash >= 0 ? n.slice(lastSlash + 1) : n;
        const lastDot = base.lastIndexOf('.');
        if (lastDot <= 0) return base; // 不處理沒有副檔名或像 .gitignore 這種
        return base.slice(0, lastDot);
    }

    /** 合併多餘換行：最多保留 2 個連續換行 */
    function normalizeLineBreaks(text) {
        if (!text || typeof text !== 'string') return text || '';
        return text.replace(/\n{3,}/g, '\n\n').trim();
    }

    /** 將秒數轉為 00:00:03 格式 */
    function formatSegmentTime(seconds) {
        const s = Number(seconds) || 0;
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = (s % 60).toFixed(1);
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(sec).padStart(4, '0')}`;
    }

    async function refreshContentTypeCounts(mid = currentMeetingID) {
        const tabs = document.querySelectorAll('.content-type-tab[data-kind]');
        if (!tabs.length) return;
        if (!mid) {
            tabs.forEach((tab) => {
                const countEl = tab.querySelector('.content-type-count');
                if (countEl) countEl.textContent = '0';
            });
            currentMeetingFilesByKind = { note: [], text: [], image: [], audio: [], summary: [] };
            currentContentCounts = { note: 0, text: 0, image: 0, audio: 0, summary: 0 };
            updateNoteEmptyHint();
            return;
        }
        try {
            const qs = new URLSearchParams({ do: 'meeting_files', id: String(mid) });
            if (CURRENT_TEAM_ID > 0) qs.set('team_ID', String(CURRENT_TEAM_ID));
            const res = await fetch(`${API_URL}?${qs.toString()}`, { method: 'GET', cache: 'no-store' });
            const raw = await res.text();
            if (raw.trim().startsWith('<')) return;
            const data = JSON.parse(raw);
            if (!data?.ok) return;
            const files = Array.isArray(data.files) ? data.files : [];
            const counts = { note: 0, text: 0, image: 0, audio: 0, summary: 0 };
            const byKind = { note: [], text: [], image: [], audio: [], summary: [] };
            files.forEach((f) => {
                const k = String(f?.kind || '').trim();
                if (Object.prototype.hasOwnProperty.call(counts, k) && !pendingClear[k]) {
                    counts[k] += 1;
                    byKind[k].push(f);
                }
            });
            currentMeetingFilesByKind = byKind;
            const noteIdx = currentFileIndexByKind.note ?? 0;
            const noteItem = byKind.note?.[noteIdx] ?? byKind.note?.[0];
            if (noteItem && !pendingClear.note) {
                noteEditor = String(noteItem.uploader_name ?? '');
                noteUpdatedTime = String(noteItem.created_d ?? '');
                noteDraftName = String(noteItem.name || '打字紀錄');
            }
            if ((noteDraftCache || '').trim().length > 0 && counts.note === 0) {
                counts.note = 1;
            }
            currentContentCounts = counts;
            tabs.forEach((tab) => {
                const kind = tab.getAttribute('data-kind') || '';
                const countEl = tab.querySelector('.content-type-count');
                if (countEl && Object.prototype.hasOwnProperty.call(counts, kind)) {
                    countEl.textContent = String(counts[kind]);
                }
            });
            updateNoteEmptyHint();
            if (!elements.meetingText?.classList.contains('is-history-mode')) {
                renderContentKindPanel(currentContentKind);
            }
        } catch (e) {
            console.error('refreshContentTypeCounts error:', e);
        }
    }

    function setActiveContentTab(kind) {
        const tabs = document.querySelectorAll('.content-type-tab[data-kind]');
        tabs.forEach((tab) => {
            tab.classList.toggle('is-active', tab.getAttribute('data-kind') === kind);
        });
        currentContentKind = kind;
        updateNoteEmptyHint();
    }

    function bindContentTypeTabs() {
        const tabs = document.querySelectorAll('.content-type-tab[data-kind]');
        if (!tabs.length) return;
        tabs.forEach((tab) => {
            if (tab.__content_click) tab.removeEventListener('click', tab.__content_click);
            tab.__content_click = async () => {
                const kind = tab.getAttribute('data-kind') || 'note';
                if (kind === currentContentKind) return;
                if (currentContentKind === 'note' && elements.meetingText && !elements.meetingText.classList.contains('is-history-mode')) {
                    const nb = elements.meetingText.querySelector('.content-note-body');
                    const nm = elements.meetingText.querySelector('.content-filemeta-name');
                    noteDraftCache = nb ? nb.innerText : elements.meetingText.innerText;
                    noteDraftName = nm ? (nm.innerText.trim() || '打字紀錄') : noteDraftName;
                }
                setActiveContentTab(kind);
                await refreshContentTypeCounts(currentMeetingID);
                renderContentKindPanel(kind);
            };
            tab.addEventListener('click', tab.__content_click);
        });
    }

    function getKindMeta(kind) {
        const meta = {
            note: { label: '打字紀錄', addLabel: '新增打字紀錄', hint: '可直接在下方輸入打字紀錄，或從左側工具新增。' },
            text: { label: '文字檔', addLabel: '新增文字檔', hint: '目前沒有文字檔內容，請上傳檔案或從工具新增。' },
            image: { label: '圖片 OCR', addLabel: '新增圖片 OCR', hint: '目前沒有圖片 OCR 內容，請上傳圖片開始辨識。' },
            audio: { label: '語音轉錄', addLabel: '新增語音檔', hint: '目前沒有語音轉錄內容，請上傳音檔或開始錄音。' },
            summary: { label: 'AI 摘要', addLabel: '產生 AI 摘要', hint: '目前沒有摘要，請先準備會議內容後再產生。' }
        };
        return meta[kind] || { label: kind, addLabel: '新增檔案', hint: '目前沒有資料。' };
    }

    function triggerAddByKind(kind) {
        if (!ensureMeetingEditable(kind)) return;
        switch (kind) {
            case 'text':
                elements.textInput?.click();
                break;
            case 'image':
                elements.imageInput?.click();
                break;
            case 'audio':
                elements.audioInput?.click();
                break;
            case 'summary':
                elements.btnSummarize?.click();
                break;
            case 'note':
            default:
                setActiveContentTab('note');
                renderContentKindPanel('note');
                if (elements.meetingText) elements.meetingText.focus();
                break;
        }
    }

    function renderContentKindPanel(kind) {
        if (!elements.meetingText || elements.meetingText.classList.contains('is-history-mode')) return;
        if (kind === 'note') {
            elements.meetingText.setAttribute('contenteditable', 'false');
            elements.meetingText.classList.remove('is-editing-record');
            const items = currentMeetingFilesByKind.note || [];
            const idx = Math.min(currentFileIndexByKind.note || 0, Math.max(0, items.length - 1));
            currentFileIndexByKind.note = idx;
            const item = items[idx] || null;
            const editorDisplay = item ? (escapeHtml(item.uploader_name || '')) : (noteEditor ? escapeHtml(noteEditor) : '—');
            const timeDisplay = item ? (escapeHtml(item.created_d || '')) : (noteUpdatedTime ? escapeHtml(noteUpdatedTime) : '尚未儲存');
            const contentToShow = item ? normalizeLineBreaks(item.content || '') : (noteDraftCache || '');
            const titleToShow = item ? (item.name || '打字紀錄') : (noteDraftName || '打字紀錄');
            const mrId = item ? (item.id || '') : '';
            const showPager = items.length > 1;
            const prevDisabled = idx <= 0;
            const nextDisabled = idx >= items.length - 1;
            const canEdit = !meetingLocked;
            const pagerHtml = showPager ? `
                <div class="content-pager">
                    <button type="button" class="content-pager-btn" data-kind="note" data-dir="prev" ${prevDisabled ? 'disabled' : ''} title="上一頁"><i class="fa-solid fa-chevron-left"></i></button>
                    <span class="content-pager-info">${idx + 1} / ${items.length}</span>
                    <button type="button" class="content-pager-btn" data-kind="note" data-dir="next" ${nextDisabled ? 'disabled' : ''} title="下一頁"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
            ` : '';
            const noteHeaderHtml = `
                <div class="content-filemeta-line" contenteditable="false">
                    <span class="content-filemeta-name">${escapeHtml(titleToShow)}</span>
                    <span class="content-filemeta-meta">編輯人：${editorDisplay} ｜ 編輯時間：${timeDisplay}</span>
                    ${canEdit ? `<button type="button" class="content-edit-toggle-btn content-workspace-btn" data-kind="note" data-mr-id="${escapeHtml(String(mrId))}">編輯</button><button type="button" class="content-clear-btn content-workspace-btn" data-action="clear" data-kind="note" data-mr-id="${escapeHtml(String(mrId))}">清除內容</button>` : ''}
                </div>
                ${pagerHtml}
                <article class="content-kind-item content-note-body content-kind-item-text" contenteditable="false" data-mr-id="${escapeHtml(String(mrId))}">${escapeHtml(contentToShow)}</article>
            `;
            elements.meetingText.innerHTML = noteHeaderHtml;
            const noteBody = elements.meetingText.querySelector('.content-note-body');
            const nameEl = elements.meetingText.querySelector('.content-filemeta-name');
            if (noteBody) {
                noteBody.innerText = contentToShow;
                noteBody.addEventListener('input', () => { noteDraftCache = noteBody.innerText; setUnsavedContentState(true); });
                noteBody.addEventListener('blur', () => { noteDraftCache = noteBody.innerText; });
            }
            if (nameEl) {
                nameEl.innerText = titleToShow;
                nameEl.addEventListener('input', () => { noteDraftName = nameEl.innerText.trim() || '打字紀錄'; setUnsavedContentState(true); });
                nameEl.addEventListener('blur', () => { noteDraftName = nameEl.innerText.trim() || '打字紀錄'; });
            }
            elements.meetingText.querySelectorAll('.content-pager-btn[data-kind="note"]').forEach(btn => {
                if (btn.disabled) return;
                btn.addEventListener('click', async () => {
                    const nb = elements.meetingText.querySelector('.content-note-body');
                    if (hasUnsavedContent && !(await confirmSwal('確認切換', '有未儲存的變更，確定要切換嗎？'))) return;
                    if (nb) noteDraftCache = nb.innerText;
                    const dir = btn.getAttribute('data-dir');
                    const noteItems = currentMeetingFilesByKind.note || [];
                    let newIdx = currentFileIndexByKind.note || 0;
                    if (dir === 'prev') newIdx = Math.max(0, newIdx - 1);
                    else if (dir === 'next') newIdx = Math.min(noteItems.length - 1, newIdx + 1);
                    currentFileIndexByKind.note = newIdx;
                    renderContentKindPanel('note');
                });
            });
            const editBtn = elements.meetingText.querySelector('.content-edit-toggle-btn[data-kind="note"]');
            if (editBtn) editBtn.addEventListener('click', () => toggleRecordEditMode());
            elements.meetingText.querySelectorAll('.content-clear-btn[data-kind="note"]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const mrId = btn.getAttribute('data-mr-id');
                    clearContent('note', mrId ? parseInt(mrId, 10) : null);
                });
            });
            updateNoteEmptyHint();
            return;
        }
        elements.meetingText.setAttribute('contenteditable', 'false');
        updateNoteEmptyHint();
        const meta = getKindMeta(kind);
        const items = currentMeetingFilesByKind[kind] || [];
        let listHtml = '';
        if (items.length) {
            if (kind === 'summary') {
                const idx = Math.min(currentFileIndexByKind[kind] || 0, items.length - 1);
                currentFileIndexByKind[kind] = idx;
                const item = items[idx] || {};
                const content = normalizeLineBreaks(item.content || '');
                const contentPoints = normalizeLineBreaks(item.content_points || '');
                const hasSummary = content.trim().length > 0;
                const hasPoints = contentPoints.trim().length > 0;
                const showPager = items.length > 1;
                const prevDisabled = idx <= 0;
                const nextDisabled = idx >= items.length - 1;
                const timeDisplay = item.created_d ? escapeHtml(item.created_d) : '—';
                const canEditSummary = !meetingLocked && (hasSummary || hasPoints);
                const summaryEditBtn = canEditSummary ? `<button type="button" class="content-edit-toggle-btn content-workspace-btn" data-kind="summary">編輯</button><button type="button" class="content-save-summary-btn content-workspace-btn content-upload-btn--primary" data-kind="summary" style="display:none;">完成</button>` : '';
                const summaryActionsHtml = `${summaryEditBtn}<button type="button" class="content-resummarize-btn content-workspace-btn" data-action="resummarize" data-kind="summary"><i class="fa-solid fa-brain"></i> 重新統整會議內容</button><button type="button" class="content-add-tasks-btn content-workspace-btn" data-action="add-tasks" data-kind="summary"><i class="fa-solid fa-list-check"></i> 新增為待辦事項</button><button type="button" class="content-clear-btn content-workspace-btn" data-action="clear" data-kind="summary">清除內容</button>`;
                const summarySectionHtml = hasSummary
                    ? `<section class="content-summary-section"><h4 class="content-summary-heading">AI 統整重點</h4><div class="content-summary-text">${escapeHtml(content)}</div></section>`
                    : '';
                const pointsSectionHtml = hasPoints
                    ? `<section class="content-summary-section"><h4 class="content-summary-heading">AI 統整條列式</h4><div class="content-summary-points">${escapeHtml(contentPoints).replace(/\n/g, '<br>')}</div></section>`
                    : '';
                const fallbackHtml = (!hasSummary && !hasPoints)
                    ? '<div class="content-summary-text content-summary-empty">（尚無摘要內容）</div>'
                    : '';
                const summaryContentHtml = summarySectionHtml || pointsSectionHtml ? (summarySectionHtml + pointsSectionHtml) : fallbackHtml;
                listHtml = `
                    <div class="content-filemeta-line" contenteditable="false">
                        <span class="content-filemeta-name">AI 摘要</span>
                        <span class="content-filemeta-meta">建立時間：${timeDisplay}</span>
                        ${summaryActionsHtml}
                    </div>
                    ${showPager ? `
                    <div class="content-pager">
                        <button type="button" class="content-pager-btn" data-kind="summary" data-dir="prev" ${prevDisabled ? 'disabled' : ''} title="上一頁"><i class="fa-solid fa-chevron-left"></i></button>
                        <span class="content-pager-info">${idx + 1} / ${items.length}</span>
                        <button type="button" class="content-pager-btn" data-kind="summary" data-dir="next" ${nextDisabled ? 'disabled' : ''} title="下一頁"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    ` : ''}
                    <article class="content-kind-item">
                        <div class="content-kind-item-text content-summary-inner">${summaryContentHtml}</div>
                    </article>
                `;
            } else {
                const idx = Math.min(currentFileIndexByKind[kind] || 0, items.length - 1);
                currentFileIndexByKind[kind] = idx;
                const item = items[idx] || {};
                const content = normalizeLineBreaks(item.content || '（此筆無內容）');
                const segments = item.segments || [];
                const hasSegments = kind === 'audio' && Array.isArray(segments) && segments.length > 0;
                const showPager = items.length > 1;
                const prevDisabled = idx <= 0;
                const nextDisabled = idx >= items.length - 1;
                const fileNameRaw = getDisplayFileName(item.name);
                const fileName = fileNameRaw ? escapeHtml(fileNameRaw) : '';
                const uploaderName = item.uploader_name ? escapeHtml(item.uploader_name) : '';
                const uploadDate = item.created_d ? escapeHtml(item.created_d) : '';
                const hasMetaLine = fileName || uploaderName || uploadDate;
                const canInlineEdit = (kind === 'text' || kind === 'image' || kind === 'audio');
                const showUpload = (kind === 'text' || kind === 'image' || kind === 'audio');
                const workspaceActionsHtml = `
                    ${canInlineEdit ? `<button type="button" class="content-edit-toggle-btn content-workspace-btn" data-kind="${escapeHtml(kind)}" data-mr-id="${item.id || ''}">編輯</button>` : ''}
                    ${showUpload ? `<button type="button" class="content-upload-btn content-upload-btn--primary content-workspace-btn" data-action="upload" data-kind="${escapeHtml(kind)}"><i class="fa-solid fa-${kind === 'image' ? 'image' : 'file-arrow-up'}"></i> 上傳${kind === 'text' ? '文字檔' : kind === 'image' ? '圖片' : '音檔'}</button>` : ''}
                    <button type="button" class="content-clear-btn content-workspace-btn" data-action="clear" data-kind="${escapeHtml(kind)}" data-mr-id="${item.id || ''}">清除內容</button>
                `;
                const metaLineHtml = hasMetaLine ? `
                    <p class="content-filemeta-line">
                        ${fileName ? `<span class="content-filemeta-name">${fileName}</span>` : ''}
                        ${(uploaderName || uploadDate) ? `<span class="content-filemeta-meta">上傳：${uploaderName || '—'} ｜ ${uploadDate || '—'}</span>` : ''}
                        ${hasSegments ? `<span class="content-diarize-badge" title="說話者分離">${escapeHtml(String(item.speaker_count || 0))} 位說話者</span>` : ''}
                        ${workspaceActionsHtml}
                    </p>
                ` : `<p class="content-filemeta-line"><span class="content-filemeta-name">${fileName || '—'}</span>${hasSegments ? `<span class="content-diarize-badge">${escapeHtml(String(item.speaker_count || 0))} 位說話者</span>` : ''}${workspaceActionsHtml}</p>`;
                const pagerHtml = showPager ? `
                    <div class="content-pager">
                        <button type="button" class="content-pager-btn" data-kind="${escapeHtml(kind)}" data-dir="prev" ${prevDisabled ? 'disabled' : ''} title="上一頁"><i class="fa-solid fa-chevron-left"></i></button>
                        <span class="content-pager-info">${idx + 1} / ${items.length}</span>
                        <button type="button" class="content-pager-btn" data-kind="${escapeHtml(kind)}" data-dir="next" ${nextDisabled ? 'disabled' : ''} title="下一頁"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                ` : '';
                const mrIdAttr = (item && item.id) ? ` data-mr-id="${escapeHtml(String(item.id))}"` : '';
                const contentHtml = hasSegments
                    ? `<div class="content-transcript-segments"${mrIdAttr}>${segments.map(s => {
                        const startStr = formatSegmentTime(s.start);
                        const endStr = formatSegmentTime(s.end);
                        return `<div class="content-transcript-segment"><span class="segment-speaker">[${escapeHtml(s.speaker || 'SPEAKER_00')}]</span> <span class="segment-time">${startStr} - ${endStr}</span>：<span class="segment-text">${escapeHtml(s.text || '')}</span></div>`;
                    }).join('')}</div>`
                    : `<div class="content-kind-item-text"${mrIdAttr}>${escapeHtml(content)}</div>`;
                listHtml = `
                    ${metaLineHtml}
                    ${pagerHtml}
                    <article class="content-kind-item">
                        ${contentHtml}
                    </article>
                `;
            }
        } else {
            const uploadBtnHtml = (kind === 'text' || kind === 'image' || kind === 'audio') ? `
                <div class="content-kind-upload-zone">
                    <button type="button" class="content-upload-btn content-upload-btn--primary" data-action="upload" data-kind="${escapeHtml(kind)}"><i class="fa-solid fa-file-arrow-up"></i> 上傳${kind === 'text' ? '文字檔' : kind === 'image' ? '圖片' : '音檔'}</button>
                    ${kind === 'audio' ? `<button type="button" class="content-upload-btn content-upload-btn--record" data-action="record" data-kind="audio"><i class="fa-solid fa-microphone"></i> 網站錄音轉逐字稿</button>` : ''}
                </div>
            ` : '';
            const summaryAiBtnHtml = (kind === 'summary') ? `
                <div class="content-kind-upload-zone">
                    <button type="button" class="content-upload-btn content-upload-btn--primary content-summarize-btn" data-action="generate-summary" data-kind="summary"><i class="fa-solid fa-brain"></i> 統整 AI</button>
                </div>
            ` : '';
            listHtml = `
                <div class="content-kind-empty">
                    <h3 class="content-kind-empty-title">尚無${escapeHtml(meta.label)}</h3>
                    <p class="content-kind-empty-desc">${escapeHtml(meta.hint)}</p>
                    ${uploadBtnHtml}
                    ${summaryAiBtnHtml}
                </div>
            `;
        }
        elements.meetingText.innerHTML = listHtml;
        elements.meetingText.querySelectorAll('.content-upload-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.getAttribute('data-action');
                const k = btn.getAttribute('data-kind') || kind;
                if (action === 'upload') {
                    triggerAddByKind(k);
                } else if (action === 'upload-image') {
                    elements.imageInput?.click();
                } else if (action === 'record') {
                    startRecording();
                } else if (action === 'generate-summary') {
                    if (typeof handleSummarize === 'function') handleSummarize();
                }
            });
        });
        elements.meetingText.querySelectorAll('.content-pager-btn').forEach(btn => {
            if (btn.disabled) return;
            btn.addEventListener('click', () => {
                const k = btn.getAttribute('data-kind') || kind;
                const dir = btn.getAttribute('data-dir');
                const items = currentMeetingFilesByKind[k] || [];
                let newIdx = currentFileIndexByKind[k] || 0;
                if (dir === 'prev') newIdx = Math.max(0, newIdx - 1);
                else if (dir === 'next') newIdx = Math.min(items.length - 1, newIdx + 1);
                currentFileIndexByKind[k] = newIdx;
                renderContentKindPanel(k);
            });
        });
        elements.meetingText.querySelectorAll('.content-edit-toggle-btn[data-kind="summary"]').forEach(btn => {
            btn.addEventListener('click', () => toggleSummaryEditMode());
        });
        elements.meetingText.querySelectorAll('.content-save-summary-btn').forEach(btn => {
            btn.addEventListener('click', () => saveSummaryContent());
        });
        elements.meetingText.querySelectorAll('.content-edit-toggle-btn').forEach(editBtn => {
            const k = editBtn.getAttribute('data-kind');
            const mrId = parseInt(editBtn.getAttribute('data-mr-id') || '0', 10);
            if (k === 'summary') return;
            if (['text','image','audio'].includes(k) && mrId) {
                editBtn.addEventListener('click', () => toggleRecordContentEditMode(mrId, k));
            } else {
                editBtn.addEventListener('click', () => toggleRecordEditMode());
            }
        });
        elements.meetingText.querySelectorAll('.content-clear-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const k = btn.getAttribute('data-kind') || kind;
                const mrIdStr = btn.getAttribute('data-mr-id');
                const mrId = mrIdStr ? parseInt(mrIdStr, 10) : null;
                clearContent(k, mrId);
            });
        });
        elements.meetingText.querySelectorAll('.content-resummarize-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (typeof handleSummarize === 'function') handleSummarize();
            });
        });
        elements.meetingText.querySelectorAll('.content-add-tasks-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (typeof handleAddPointsToTasks === 'function') handleAddPointsToTasks();
            });
        });
    }

    function resolveAvatarUrl(path) {
        const p = String(path || '').trim();
        if (!p) return '';
        if (/^(https?:)?\/\//i.test(p) || p.startsWith('data:') || p.startsWith('blob:')) return p;
        if (p.startsWith('/')) return p;
        if (PROJECT_BASE) return `${PROJECT_BASE}/${p.replace(/^\.?\//, '')}`;
        return p;
    }

    function buildAvatarCandidates(path) {
        const p = String(path || '').trim();
        if (!p) return [];
        if (/^(https?:)?\/\//i.test(p) || p.startsWith('data:') || p.startsWith('blob:')) return [p];
        // 避免把姓名等非圖片字串當路徑，造成大量 404
        if (!/\.(png|jpe?g|gif|webp|bmp|svg|avif)(\?.*)?$/i.test(p)) return [];
        if (p.startsWith('/')) return [p];

        const clean = p.replace(/^\.?\//, '');
        const encodedClean = encodeURI(clean);
        const out = [];
        if (PROJECT_BASE) {
            out.push(`${PROJECT_BASE}/${encodedClean}`);
            out.push(`${PROJECT_BASE}/uploads/user/${encodedClean}`);
            out.push(`${PROJECT_BASE}/uploads/${encodedClean}`);
        }
        out.push(`/${encodedClean}`);
        out.push(`/uploads/user/${encodedClean}`);
        out.push(`/uploads/${encodedClean}`);
        // 去重
        return Array.from(new Set(out));
    }

    function getInitialMeetingId() {
        try {
            const qs = new URLSearchParams(window.location.search || '');
            const qMid = parseInt(qs.get('m_ID') || '', 10);
            if (qMid > 0) return qMid;
        } catch (_) { }

        try {
            const hash = window.location.hash || '';
            const qpos = hash.indexOf('?');
            if (qpos >= 0) {
                const hashQs = new URLSearchParams(hash.slice(qpos + 1));
                const hMid = parseInt(hashQs.get('m_ID') || '', 10);
                if (hMid > 0) return hMid;
            }
        } catch (_) { }
        return null;
    }

    function getCreateFlagFromLocation() {
        try {
            const qs = new URLSearchParams(window.location.search || '');
            const c = qs.get('create');
            if (c === '1' || c === 'true') return true;
        } catch (_) { }
        try {
            const hash = window.location.hash || '';
            const qpos = hash.indexOf('?');
            if (qpos >= 0) {
                const hashQs = new URLSearchParams(hash.slice(qpos + 1));
                const c = hashQs.get('create');
                return c === '1' || c === 'true';
            }
        } catch (_) { }
        return false;
    }

    function updateHashToMeeting(teamId, mId) {
        const base = (window.location.hash || '').split('?')[0] || '#pages/meeting.php';
        const qs = new URLSearchParams();
        if (teamId > 0) qs.set('team_ID', String(teamId));
        if (mId > 0) qs.set('m_ID', String(mId));
        const newHash = base + '?' + qs.toString();
        if (window.history && window.history.replaceState) {
            try {
                window.history.replaceState(null, '', window.location.pathname + window.location.search + newHash);
            } catch (_) {
                window.location.hash = newHash;
            }
        } else {
            window.location.hash = newHash;
        }
    }


    function renderFilesList(files, m_ID) {
        elements.meetingFiles.innerHTML = files.map(f => `
    <div class="file-item" data-kind="${f.kind}" data-id="${f.id || ''}"
         style="padding:10px; border-radius:10px; cursor:pointer; margin-bottom:8px; background:#fff;">
      <div style="display:flex; align-items:center; gap:8px;">
        <div style="font-weight:700; color:#111827;">${escapeHtml(f.label)}</div>
      </div>
      <div style="font-size:12px; color:#6b7280; margin-top:2px;">
        ${escapeHtml(f.created_d || '')} ${
            f.name
                ? '｜' + escapeHtml(getDisplayFileName(f.name))
                : ''
        }
      </div>
    </div>
  `).join('');

        elements.meetingFiles.querySelectorAll('.file-item').forEach(el => {
            el.addEventListener('click', async () => {
                const kind = el.getAttribute('data-kind');
                const id = el.getAttribute('data-id');
                const item = files.find(x => x.kind === kind && String(x.id || '') === String(id || ''));
                if (item) await openFileItem(item);
            });
        });
    }

    async function openFileItem(item) {
        viewingHistory = true;

        // 右側顯示內容：直接塞進 editor
        // 注意：這裡是「瀏覽歷史」，我建議先關掉 contenteditable，避免你一打開歷史就把它當今日內容去 save。
        if (elements.meetingText) {
            elements.meetingText.innerText = item.content || '';
            elements.meetingText.setAttribute('contenteditable', 'false');
        }

        // 如果你希望點「打字紀錄」可以進入可編輯再存回該 meeting：
        // 你可以把 note 的 contenteditable 開回 true，並把 currentMeetingID 設為該 meeting
        // （但這會變成「回頭編輯歷史」，看你要不要）
        // if (item.kind === 'note') {
        //   currentMeetingID = item.m_ID;
        //   elements.meetingText.setAttribute('contenteditable', 'true');
        // }
    }

    function fileIcon(kind) {
        return '';
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (m) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    // --- 啟動函式 ---
    async function initMeetingPage() {
        console.log("初始化會議功能...");

        // 檢查元素是否存在，避免報錯
        if (!elements.meetingText) {
            console.warn("找不到 meetingText 元素，JS 可能在 HTML 載入前就執行了");
            return;
        }
        bindTitleInlineEdit();
        bindBeforeUnloadGuard();
        loadMeetingHistory();
        bindHistoryFilters();
        bindContentTypeTabs();
        bindAuxPanelSwitch();
        bindToolCollapse();
        bindHistoryCollapse();

        let initialMid = getInitialMeetingId();
        // 從會議總覽點「+新增」進入 meeting.php?team_ID=X&create=1 時，自動建立會議並開啟
        if (getCreateFlagFromLocation() && CURRENT_TEAM_ID && !initialMid) {
            setBusy(true, '建立會議中...', '請稍候');
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        do: 'create_meeting',
                        team_ID: CURRENT_TEAM_ID,
                        title: '新會議'
                    })
                });
                const data = await res.json();
                setBusy(false);
                if (data.ok && data.m_ID) {
                    initialMid = data.m_ID;
                    currentMeetingID = data.m_ID;
                    lastKnownLatestMid = data.m_ID;
                    updateHashToMeeting(CURRENT_TEAM_ID, data.m_ID);
                    if (data.m_date) updateDateBadges(data);
                    if (elements.meetingTitle) elements.meetingTitle.innerText = '新會議';
                }
            } catch (e) {
                setBusy(false);
                console.error(e);
            }
        }

        // 從會議列表帶 m_ID 進來時，優先直接開該歷史會議（或剛由 create=1 建立的會議）
        if (initialMid) {
            currentMeetingID = initialMid;
            lastKnownLatestMid = initialMid;
            applyNoMeetingState(false);
            openMeetingFiles(initialMid);
            loadAttendance();
        } else {
            // 有紀錄時應顯示最後一筆，僅在「該組別真的沒有會議」時才顯示尚無會議
            // 不先設 applyNoMeetingState(true)，由 loadMeetingData 依 API 結果決定
            if (elements.meetingText) {
                elements.meetingText.classList.remove('is-history-mode');
                elements.meetingText.setAttribute('contenteditable', 'false');
                elements.meetingText.innerText = '';
            }
            loadMeetingData();
            loadAttendance();
        }
        if (CURRENT_TEAM_ID) startNewMeetingPolling();
        // 停用自動輪詢，避免頁面定時刷新造成體感「自己跳動」
        if (attendanceRefreshTimer) {
            clearInterval(attendanceRefreshTimer);
            attendanceRefreshTimer = null;
        }

        // 綁定事件 (因為沒有 DOMContentLoaded，這裡直接執行)
        bindEvents();
        if (elements.meetingText) {
            elements.meetingText.addEventListener('input', (e) => {
                if (currentContentKind === 'note' && !elements.meetingText.classList.contains('is-history-mode')) {
                    const nb = elements.meetingText.querySelector('.content-note-body');
                    noteDraftCache = nb ? nb.innerText : elements.meetingText.innerText;
                    setUnsavedContentState(true);
                }
            });
        }
        setUnsavedContentState(false);
    }
    function bindAuxPanelSwitch() {
        const buttons = document.querySelectorAll('.aux-switch-btn');
        if (!buttons.length) return;
        const historyBody = document.getElementById('historyBody');
        const toolBody = document.getElementById('toolBody');
        const show = (targetId) => {
            const showHistory = targetId === 'historyBody';
            if (historyBody) historyBody.style.display = showHistory ? 'block' : 'none';
            if (toolBody) toolBody.style.display = showHistory ? 'none' : 'block';
            buttons.forEach(btn => {
                const active = btn.getAttribute('data-target') === targetId;
                btn.classList.toggle('is-active', active);
            });
        };
        buttons.forEach(btn => {
            if (btn.__aux_click) btn.removeEventListener('click', btn.__aux_click);
            btn.__aux_click = () => show(btn.getAttribute('data-target') || 'historyBody');
            btn.addEventListener('click', btn.__aux_click);
        });
        show('historyBody');
    }
    function bindToolCollapse() {
        if (!elements.toolToggle || !elements.toolBody) return;
        const chevron = elements.toolChevron || document.getElementById('toolChevron');

        const open = () => {
            elements.toolBody.style.display = 'block';
            elements.toolToggle.classList.add('is-open');
            if (chevron) chevron.style.transform = 'rotate(180deg)';
        };
        const close = () => {
            elements.toolBody.style.display = 'none';
            elements.toolToggle.classList.remove('is-open');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        };

        // 你想預設收起：維持 close()
        close();

        // SPA 重複載入保護
        if (elements.toolToggle.__tool_click) {
            elements.toolToggle.removeEventListener('click', elements.toolToggle.__tool_click);
        }
        elements.toolToggle.__tool_click = () => {
            const isOpen = elements.toolBody.style.display !== 'none';
            isOpen ? close() : open();
        };
        elements.toolToggle.addEventListener('click', elements.toolToggle.__tool_click);
    }
    function bindHistoryCollapse() {
        const toggle = document.getElementById('historyToggle');
        const body = document.getElementById('historyBody');
        const chevron = document.getElementById('historyChevron');
        if (!toggle || !body) return;

        const open = () => {
            body.style.display = 'block';
            toggle.classList.add('is-open');
            if (chevron) chevron.style.transform = 'rotate(180deg)';
        };
        const close = () => {
            body.style.display = 'none';
            toggle.classList.remove('is-open');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        };

        // 預設收起（你想預設展開就改 open()）
        close();

        // SPA 重複載入保護
        if (toggle.__hist_click) toggle.removeEventListener('click', toggle.__hist_click);
        toggle.__hist_click = () => {
            const isOpen = body.style.display !== 'none';
            isOpen ? close() : open();
        };
        toggle.addEventListener('click', toggle.__hist_click);
    }
function bindTitleInlineEdit(){
  const el = elements.meetingTitle || document.getElementById('meetingTitle');
  if (!el) return;

  // 避免 SPA 重複綁定
  if (el.__bound) return;
  el.__bound = true;

  let t = null;
  const debounceSave = () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      if (meetingLocked) return;
      const title = el.innerText.trim();
      if (!title) return;

      try{
        // ✅ 這裡呼叫 API 存回 meetingdata.m_title
        await fetch(API_URL, {
          method: 'POST',
          headers: { 'Content-Type':'application/json' },
          body: JSON.stringify({
            do: 'meeting_update_meta',
            m_ID: currentMeetingID,
            m_title: title,
            team_ID: CURRENT_TEAM_ID || undefined
          })
        });
      }catch(e){
        console.error('save title failed', e);
      }
    }, 400);
  };

  el.addEventListener('input', debounceSave);

  // Enter 不要換行（contenteditable 預設會換行）
  el.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter'){
      e.preventDefault();
      el.blur();
    }
  });
}

    function bindHistoryFilters() {
        // --- 1) 抓 DOM（避免 elements 尚未收集 or SPA 載入時不存在） ---
        const kwEl = elements.historyKeyword || document.getElementById('historyKeyword');
        const fromEl = elements.historyDateFrom || document.getElementById('historyDateFrom');
        const toEl = elements.historyDateTo || document.getElementById('historyDateTo');

        // 三個都不存在就不綁
        if (!kwEl && !fromEl && !toEl) return;

        // --- 2) debounce 工具 ---
        const debounce = (fn, delay = 250) => {
            let t;
            return (...args) => {
                clearTimeout(t);
                t = setTimeout(() => fn(...args), delay);
            };
        };

        // --- 3) 共同刷新函式（含日期防呆） ---
        const normalizeDatesAndReload = () => {
            // 若兩個日期都有填，且 from > to，則交換
            const fromVal = fromEl?.value || '';
            const toVal = toEl?.value || '';
            if (fromVal && toVal && fromVal > toVal) {
                // swap
                const tmp = fromEl.value;
                fromEl.value = toEl.value;
                toEl.value = tmp;
            }
            loadMeetingHistory();
        };

        const reload = debounce(normalizeDatesAndReload, 250);

        // --- 4) SPA 重複綁定保護：先移除舊的 ---
        // 用元素私有欄位記錄 handler，避免每次 load 都疊加
        const safeBind = (el, eventName, handler) => {
            if (!el) return;
            const key = `__mh_${eventName}`;
            if (el[key]) el.removeEventListener(eventName, el[key]);
            el[key] = handler;
            el.addEventListener(eventName, handler);
        };

        // --- 5) 綁定事件 ---
        safeBind(kwEl, 'input', reload);
        safeBind(fromEl, 'change', reload);
        safeBind(toEl, 'change', reload);

        // --- 6) 可選：預設日期區間（近 30 天）---
        // 你如果不想要預設值，把這段整段註解掉即可
        if (fromEl && toEl && !fromEl.value && !toEl.value) {
            const today = new Date();
            const toStr = today.toISOString().slice(0, 10);
            const past = new Date(today);
            past.setDate(today.getDate() - 30);
            const fromStr = past.toISOString().slice(0, 10);

            fromEl.value = fromStr;
            toEl.value = toStr;
        }

        // --- 7) 第一次載入（確保畫面一進來就有資料） ---
        loadMeetingHistory();
    }


    // --- 綁定所有按鈕事件 ---
    function bindEvents() {
        if (elements.uploadTextZone) elements.uploadTextZone.onclick = () => elements.textInput.click();
        if (elements.textInput) elements.textInput.onchange = handleTextUpload;

        // 1. 錄音
        if (elements.recordBtn) elements.recordBtn.onclick = startRecording;
        if (elements.stopRecordBtn) elements.stopRecordBtn.onclick = stopRecording;

        // 圖片 OCR
        if (elements.uploadImageZone) elements.uploadImageZone.onclick = () => elements.imageInput.click();
        if (elements.imageInput) elements.imageInput.onchange = handleImageUpload;

        // 音檔轉錄
        if (elements.uploadAudioZone) elements.uploadAudioZone.onclick = () => elements.audioInput.click();
        if (elements.audioInput) elements.audioInput.onchange = handleAudioUpload;

        // 3. AI 統整
        if (elements.btnSummarize) elements.btnSummarize.onclick = handleSummarize;

        // 4. 簽到
        if (elements.btnCheckIn) elements.btnCheckIn.onclick = handleCheckIn;

        // 5. 清除與自動儲存（清除按鈕：有當前項目時刪除該筆，否則清除整類）
        if (elements.btnClear) elements.btnClear.onclick = () => {
            const kind = currentContentKind;
            const items = currentMeetingFilesByKind[kind] || [];
            const idx = currentFileIndexByKind[kind] || 0;
            const item = items[idx];
            const mrId = (kind !== 'summary' && item && item.id) ? item.id : null;
            clearContent(kind, mrId);
        };
        if (elements.btnConfirmMeeting) elements.btnConfirmMeeting.onclick = confirmMeeting;
        if (elements.btnReopenMeeting) elements.btnReopenMeeting.onclick = reopenMeeting;
        if (elements.btnDeleteMeeting) elements.btnDeleteMeeting.onclick = deleteMeeting;
        if (elements.btnCreateMeeting) elements.btnCreateMeeting.onclick = openCreateMeetingModal;
        const btnCreateInHeader = document.getElementById('btnCreateMeetingInHeader');
        if (btnCreateInHeader) btnCreateInHeader.onclick = openCreateMeetingModal;
        if (elements.btnCancelCreate) elements.btnCancelCreate.onclick = closeCreateMeetingModal;
        if (elements.btnConfirmCreate) elements.btnConfirmCreate.onclick = submitCreateMeeting;
        const btnCreateModalClose = document.getElementById('btnCreateModalClose');
        if (btnCreateModalClose) btnCreateModalClose.onclick = closeCreateMeetingModal;
        if (elements.createMeetingModal) {
            elements.createMeetingModal.onclick = function (e) {
                if (e.target === elements.createMeetingModal) closeCreateMeetingModal();
            };
        }

        // 建立會議 Modal：檔案選擇區塊（點擊按鈕或區域開啟選檔；清除按鈕）
        function triggerCreateModalFileInput(forId) {
            const input = document.getElementById(forId);
            if (input) input.click();
        }
        document.querySelectorAll('.create-meeting-modal .file-picker-btn').forEach(btn => {
            const forId = btn.getAttribute('data-for');
            if (forId) btn.addEventListener('click', (e) => { e.stopPropagation(); triggerCreateModalFileInput(forId); });
        });
        document.querySelectorAll('.create-meeting-modal .file-picker-zone').forEach(zone => {
            const forId = zone.getAttribute('data-for');
            if (forId) zone.addEventListener('click', (e) => { if (!e.target.closest('.file-picker-btn') && !e.target.closest('.file-picker-clear')) triggerCreateModalFileInput(forId); });
        });
        document.querySelectorAll('.create-meeting-modal .file-picker-clear').forEach(btn => {
            const forId = btn.getAttribute('data-for');
            if (forId) btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const input = document.getElementById(forId);
                if (input) { input.value = ''; updateCreateModalFilePickerDisplay(forId); }
            });
        });
        ['createModalTextFiles', 'createModalImageFiles', 'createModalAudioFiles'].forEach(id => {
            const input = document.getElementById(id);
            if (input) input.addEventListener('change', () => updateCreateModalFilePickerDisplay(id));
        });
    }

    async function confirmMeeting() {
        if (!IS_TEACHER) return;
        if (meetingLocked) return;
        if (!(await confirmSwal('確認會議', '確認此次會議後，組員將不能再修改出席與會議內容，是否繼續？'))) return;

        setBusy(true, "確認會議中...", "鎖定本次會議資料");
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    do: 'meeting_confirm',
                    m_ID: currentMeetingID,
                    team_ID: CURRENT_TEAM_ID || undefined
                })
            });
            const data = await res.json();
            if (!data.ok) {
                const isEditing = (data.msg || '').indexOf('正在編輯') !== -1;
                if (isEditing && window.Swal) {
                    const { value: waitThenConfirm } = await Swal.fire({
                        icon: 'question',
                        title: '有人正在編輯',
                        text: data.msg + ' 是否在對方填寫完畢後自動確認會議？',
                        showCancelButton: true,
                        confirmButtonText: '是，稍後自動確認',
                        cancelButtonText: '取消'
                    });
                    if (waitThenConfirm) {
                        startConfirmWhenIdle();
                        return;
                    }
                }
                toast('error', data.msg || '確認失敗');
                return;
            }
            if (data.m_ID) currentMeetingID = data.m_ID;
            meetingLockedRaw = true;
            meetingReopened = false;
            applyMeetingLockState(true, false, data.msg || undefined);
            await loadAttendance();
            toast('success', data.msg || '已確認此次會議');
        } catch (e) {
            console.error(e);
            toast('error', '確認會議失敗');
        } finally {
            setBusy(false);
        }
    }

    async function reopenMeeting() {
        if (!IS_TEACHER) return;
        if (!meetingLockedRaw || meetingReopened) return;
        if (!(await confirmSwal('開放修改', '開放後，組員與指導老師可再編輯會議內容與出席狀態，是否繼續？'))) return;

        setBusy(true, "開放修改中...", "正在更新會議狀態");
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    do: 'meeting_reopen',
                    m_ID: currentMeetingID,
                    team_ID: CURRENT_TEAM_ID || undefined
                })
            });
            const data = await res.json();
            if (!data.ok) {
                toast('error', data.msg || '開放修改失敗');
                return;
            }
            meetingReopened = true;
            applyMeetingLockState(true, true);
            await loadAttendance();
            toast('success', data.msg || '已開放修改');
        } catch (e) {
            console.error(e);
            toast('error', '開放修改失敗');
        } finally {
            setBusy(false);
        }
    }

    window.saveMeetingNote = async function () {
        if (!ensureMeetingEditable()) return;
        if (currentContentKind === 'note' && elements.meetingText) {
            const noteBody = elements.meetingText.querySelector('.content-note-body');
            const nameEl = elements.meetingText.querySelector('.content-filemeta-name');
            noteDraftCache = noteBody ? noteBody.innerText : elements.meetingText.innerText;
            noteDraftName = nameEl ? (nameEl.innerText.trim() || '打字紀錄') : noteDraftName;
        }

        const clearedKinds = Object.keys(pendingClear).filter(k => pendingClear[k]);
        const content = (noteDraftCache || '').trim();
        const clearingNote = clearedKinds.includes('note');
        const kindsToClear = clearingNote && content ? clearedKinds.filter(k => k !== 'note') : clearedKinds;
        if (kindsToClear.length === 0 && !content) {
            toast('info', '沒有變更可儲存');
            return;
        }
        setBusy(true, "儲存中...", "正在寫入資料庫…");
        try {
            for (const kind of kindsToClear) {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ do: 'meeting_clear_kind', m_ID: currentMeetingID, team_ID: CURRENT_TEAM_ID || undefined, kind })
                });
                const data = await res.json();
                if (!data.ok) {
                    toast('error', data.msg || '清除失敗');
                    return;
                }
            }
            for (const k of kindsToClear) {
                delete pendingClear[k];
                currentMeetingFilesByKind[k] = [];
                currentContentCounts[k] = 0;
            }

            if (content) {
                const noteBodyEl = elements.meetingText?.querySelector('.content-note-body');
                const nameEl = elements.meetingText?.querySelector('.content-filemeta-name');
                const mrId = noteBodyEl ? parseInt(noteBodyEl.getAttribute('data-mr-id') || '0', 10) : 0;
                const mrName = (nameEl ? nameEl.innerText.trim() : noteDraftName) || '打字紀錄';
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        do: 'meeting_save',
                        m_ID: currentMeetingID,
                        team_ID: CURRENT_TEAM_ID || undefined,
                        content: content,
                        mr_ID: mrId || undefined,
                        mr_name: mrName.substring(0, 50)
                    })
                });
                const data = await res.json();
                if (!data.ok) {
                    toast('error', data.msg || '儲存失敗');
                    return;
                }
                if (data.m_ID) currentMeetingID = data.m_ID;
                delete pendingClear.note;
                noteDraftName = (nameEl ? nameEl.innerText.trim() : noteDraftName) || '打字紀錄';
                noteEditor = window.MEETING_USER?.u_name || '';
                const now = new Date();
                noteUpdatedTime = now.getFullYear() + '/' + String(now.getMonth() + 1).padStart(2, '0') + '/' + String(now.getDate()).padStart(2, '0') + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
                myEditLockRecord = false;
                elements.meetingText?.classList.remove('is-editing-record');
                const editBtn = elements.meetingText?.querySelector('.content-edit-toggle-btn');
                if (editBtn) editBtn.textContent = '編輯';
                callEditLock(0, data.mr_ID || getCurrentMrId(), null);
            }

            setUnsavedContentState(false);
            await refreshContentTypeCounts(currentMeetingID);
            renderContentKindPanel(currentContentKind);
            toast('success', '儲存成功');
        } finally {
            setBusy(false);
        }
    };



    // --- 功能實作 ---

    async function loadMeetingData() {
        try {
            const q = new URLSearchParams({ do: 'meeting_load' });
            const teamId = getTeamIdFromLocation() || CURRENT_TEAM_ID || (window.MEETING_TEAM ? Number(window.MEETING_TEAM) : 0);
            if (teamId > 0) q.set('team_ID', String(teamId));
            const res = await fetch(`${API_URL}?${q.toString()}`, { cache: 'no-store' });
            const text = await res.text();   // 先拿原文

            // 如果後端噴 HTML，這裡直接抓出來給你看
            if (text.trim().startsWith('<')) {
                console.error("API 回傳了 HTML（代表 PHP 爆了）:", text);
                toast('error', "meeting_api.php 回傳 HTML（PHP 錯誤），請看 Console");
                return;
            }

            const data = JSON.parse(text);
            if (data.ok && data.m_ID) {
                currentMeetingID = data.m_ID;
                lastKnownLatestMid = data.m_ID;
                try {
                    await fetch(API_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ do: 'meeting_clear_my_locks', m_ID: data.m_ID, team_ID: CURRENT_TEAM_ID || undefined })
                    });
                } catch (_) {}
                applyNoMeetingState(false);
                if (elements.meetingText) {
                    elements.meetingText.classList.remove('is-history-mode');
                    elements.meetingText.setAttribute('contenteditable', 'false');
                    noteDraftCache = String(data.note ?? '');
                    noteEditor = String(data.note_editor ?? '');
                    noteUpdatedTime = String(data.note_updated_d ?? '');
                    setActiveContentTab('note');
                    renderContentKindPanel('note');
                }
                if (elements.meetingTitle && data.m_title) {
                    elements.meetingTitle.innerText = data.m_title;
                }
                updateDateBadges(data);
                // AI 摘要改由 summary 分頁讀取展示，不插入手寫內容
                meetingLockedRaw = Boolean(data.locked);
                meetingReopened = Boolean(data.reopened);
                applyMeetingLockState(data.locked, data.can_edit);
                setUnsavedContentState(false);
                await refreshContentTypeCounts(currentMeetingID);
                startEditStatusPolling();
            } else {
                // meeting_load 無資料時，嘗試從歷史列表取最後一筆（該組別有紀錄應顯示最後一筆）
                const teamId = getTeamIdFromLocation() || CURRENT_TEAM_ID || (window.MEETING_TEAM ? Number(window.MEETING_TEAM) : 0);
                const listQs = new URLSearchParams({ do: 'meeting_list' });
                if (teamId > 0) listQs.set('team_ID', String(teamId));
                try {
                    const listRes = await fetch(`${API_URL}?${listQs.toString()}`, { cache: 'no-store' });
                    const listText = await listRes.text();
                    if (!listText.trim().startsWith('<')) {
                        const listData = JSON.parse(listText);
                        const list = listData.list || [];
                        if (list.length > 0) {
                            const lastMid = list[0].m_ID;
                            if (lastMid) {
                                currentMeetingID = lastMid;
                                applyNoMeetingState(false);
                                await openMeetingFiles(lastMid);
                                setActiveContentTab('note');
                                renderContentKindPanel('note');
                                return;
                            }
                        }
                    }
                } catch (_) { }
                applyNoMeetingState(true);
                if (elements.meetingText) {
                    elements.meetingText.innerText = '';
                    elements.meetingText.classList.remove('is-history-mode');
                    elements.meetingText.setAttribute('contenteditable', 'false');
                }
                if (elements.meetingTitle) elements.meetingTitle.innerText = '尚無會議';
            }
        } catch (e) {
            console.error("無法載入 meeting_load:", e);
        }
    }

    async function handleTextUpload(e) {
        if (!ensureMeetingEditable()) return;
        const files = e.target.files;
        if (!files || !files.length) return;

        for (let file of files) {
            if (currentContentKind === 'note') {
                setBusy(true, "文字檔處理中...", `正在讀取：${file.name}`);
                if (window.Swal) Swal.fire({ title: '文字檔處理中...', html: `正在讀取：${file.name}，請稍候`, allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
                try {
                    const text = await file.text();
                    noteDraftCache = (noteDraftCache || '') + (noteDraftCache ? '\n\n' : '') + (text || '');
                    setUnsavedContentState(true);
                    renderContentKindPanel('note');
                } catch (err) {
                    toast('error', '無法讀取檔案：' + (err.message || ''));
                } finally {
                    if (window.Swal) Swal.close();
                    setBusy(false);
                }
            } else {
                setBusy(true, "文字檔處理中...", `正在上傳：${file.name}`);
                if (window.Swal) Swal.fire({ title: '文字檔處理中...', html: `正在上傳：${file.name}，請稍候`, allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
                try {
                    const formData = new FormData();
                    formData.append('do', 'meeting_upload_text');
                    formData.append('file', file);
                    if (currentMeetingID) formData.append('m_ID', currentMeetingID);

                    const res = await fetch(API_URL, { method: 'POST', body: formData });
                    const data = await res.json();

                    if (data.ok) {
                        currentMeetingID = data.m_ID;
                        addSystemMessage(`[文字檔] ${data.content}`);
                        setUnsavedContentState(true);
                        await refreshContentTypeCounts(currentMeetingID);
                    } else {
                        toast('error', data.msg || '文字檔上傳失敗');
                    }
                } finally {
                    if (window.Swal) Swal.close();
                    setBusy(false);
                }
            }
        }
        elements.textInput.value = '';
    }

    async function startRecording() {
        if (!ensureMeetingEditable()) return;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            toast('warning', '瀏覽器不支援錄音或未開啟 HTTPS');
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];
            mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
            mediaRecorder.onstop = async () => {
                const blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                uploadAudioBlob(blob);

                stream.getTracks().forEach(t => t.stop());
            };
            mediaRecorder.start();
            startTimer();
            toggleRecordingUI(true);
        } catch (err) {
            console.error(err);
            toast('error', '無法存取麥克風');
        }
    }

    function stopRecording() {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
            stopTimer();
            toggleRecordingUI(false);
        }
    }

    async function uploadAudioBlob(blob) {
        if (!ensureMeetingEditable()) return;
        const formData = new FormData();
        formData.append('do', 'meeting_transcribe_audio');
        formData.append('file', blob, 'voice_record.webm');
        formData.append('diarize', '1');

        if (currentMeetingID) formData.append('m_ID', currentMeetingID);

        setBusy(true, "語音轉錄中...", "正在處理錄音，請稍候…");
        if (window.Swal) Swal.fire({ title: '語音轉錄中...', html: '正在處理錄音，請稍候…', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
        try {
            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const raw = await res.text();
            let data;
            try { data = JSON.parse(raw); } catch (e) {
                console.error('語音轉錄 API 回傳非 JSON:', raw?.substring(0, 500));
                toast('error', '轉錄失敗：伺服器回傳格式錯誤');
                return;
            }
            if (data.ok) {
                currentMeetingID = data.m_ID;
                addSystemMessage(`[語音] ${data.content}`);
                setUnsavedContentState(true);
                await refreshContentTypeCounts(currentMeetingID);
                setActiveContentTab('audio');
                renderContentKindPanel('audio');
                toast('success', '語音轉錄完成');
            } else {
                toast('error', '轉錄失敗: ' + (data.msg || data.error || '未知錯誤'));
            }
        } catch (e) { console.error(e); toast('error', '轉錄失敗：' + (e.message || '上傳錯誤')); }
        finally { if (window.Swal) Swal.close(); setBusy(false); }
    }

    async function handleImageUpload(e) {
        if (!ensureMeetingEditable()) return;
        const files = e.target.files;
        if (!files.length) return;

        for (let file of files) {
            setBusy(true, "圖片 OCR 中...", `正在辨識：${file.name}`);
            if (window.Swal) Swal.fire({ title: '圖片 OCR 中...', html: `正在辨識：${file.name}，請稍候`, allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
            try {
                const formData = new FormData();
                formData.append('do', 'meeting_ocr_image');
                formData.append('file', file);
                if (currentMeetingID) formData.append('m_ID', currentMeetingID);

                const res = await fetch(API_URL, { method: 'POST', body: formData });
                const data = await res.json();

                if (data.ok) {
                    currentMeetingID = data.m_ID;
                    addSystemMessage(`[OCR] ${data.content}`);
                    setUnsavedContentState(true);
                    await refreshContentTypeCounts(currentMeetingID);
                } else {
                    toast('error', data.msg || 'OCR 失敗');
                }
            } finally {
                if (window.Swal) Swal.close();
                setBusy(false);
            }
        }

        elements.imageInput.value = '';
    }
    async function handleAudioUpload(e) {
        if (!ensureMeetingEditable()) return;
        const files = e.target.files;
        if (!files.length) return;

        for (let file of files) {
            setBusy(true, "語音轉錄中...", `正在轉錄：${file.name}`);
            if (window.Swal) Swal.fire({ title: '語音轉錄中...', html: `正在轉錄：${file.name}，請稍候`, allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
            try {
                const formData = new FormData();
                formData.append('do', 'meeting_transcribe_audio');
                formData.append('file', file);
                formData.append('diarize', '1');
                if (currentMeetingID) formData.append('m_ID', currentMeetingID);

                const res = await fetch(API_URL, { method: 'POST', body: formData });
                const raw = await res.text();
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (parseErr) {
                    console.error('語音轉錄 API 回傳非 JSON:', raw?.substring(0, 500));
                    toast('error', '轉錄失敗：伺服器回傳格式錯誤，請查看 Console');
                    return;
                }

                if (data.ok) {
                    currentMeetingID = data.m_ID;
                    addSystemMessage(`[語音] ${data.content}`);
                    setUnsavedContentState(true);
                    await refreshContentTypeCounts(currentMeetingID);
                    setActiveContentTab('audio');
                    renderContentKindPanel('audio');
                    toast('success', '語音轉錄完成');
                } else {
                    toast('error', '轉錄失敗：' + (data.msg || data.error || '未知錯誤'));
                }
            } catch (e) {
                console.error('語音轉錄錯誤:', e);
                toast('error', '轉錄失敗：' + (e.message || '網路或伺服器錯誤'));
            } finally {
                if (window.Swal) Swal.close();
                setBusy(false);
            }
        }

        elements.audioInput.value = '';
    }


    async function handleSummarize() {
        if (!ensureMeetingEditable()) return;
        // 由後端從 DB 彙整內容工作區「所有」資料：打字紀錄、文字檔、圖片 OCR、語音轉錄、既有 AI 摘要
        if (!currentMeetingID) {
            toast('warning', '請先選擇或建立會議');
            return;
        }

        setBusy(true, "AI 統整中...", "正在讀取文字並生成摘要/任務…");
        if (window.Swal) {
            Swal.fire({
                title: 'AI 統整中...',
                html: '正在讀取文字並生成摘要，請稍候，不要關閉頁面。',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => { Swal.showLoading(); }
            });
        }
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ do: 'meeting_summarize', m_ID: currentMeetingID, team_ID: CURRENT_TEAM_ID || undefined, use_server_content: true })
            });

            const resultText = await res.text();
            const data = JSON.parse(resultText);

            if (data.ok) {
                if (data.m_ID) currentMeetingID = data.m_ID;
                await refreshContentTypeCounts(currentMeetingID);
                setActiveContentTab('summary');
                renderContentKindPanel('summary');
                toast('success', '統整完成！');
            } else {
                toast('error', data.msg || 'AI 統整失敗');
            }
        } catch (e) {
            console.error(e);
            toast('error', '連線或伺服器錯誤');
        } finally {
            if (window.Swal) Swal.close();
            setBusy(false);
        }
    }

    async function handleAddPointsToTasks() {
        if (!currentMeetingID) {
            toast('warning', '請先選擇或建立會議');
            return;
        }
        const summaryItems = currentMeetingFilesByKind?.summary || [];
        const idx = Math.min(currentFileIndexByKind?.summary || 0, Math.max(0, summaryItems.length - 1));
        const item = summaryItems[idx] || {};
        const contentPoints = normalizeLineBreaks(item.content_points || '');
        const lines = contentPoints.split(/\r?\n/).map(s => s.trim()).filter(s => s !== '');
        if (lines.length === 0) {
            toast('warning', '尚無 AI 統整條列式內容，請先完成 AI 摘要');
            return;
        }

        const listId = 'add-tasks-points-list';
        const html = `
            <p class="text-muted" style="margin-bottom:12px;font-size:13px;">勾選要新增的項目、雙擊可編輯、或點「移除」刪除不需要的項目</p>
            <div id="${listId}" class="add-tasks-list" style="max-height:320px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fafafa;">
                ${lines.map((line, i) => `
                    <div class="add-tasks-item" data-idx="${i}" style="display:flex;align-items:flex-start;gap:8px;padding:8px;margin-bottom:6px;background:#fff;border-radius:6px;border:1px solid #e5e7eb;">
                        <label style="flex-shrink:0;cursor:pointer;margin-top:2px;">
                            <input type="checkbox" class="add-tasks-check" checked data-idx="${i}">
                        </label>
                        <span class="add-tasks-text" style="flex:1;font-size:13px;line-height:1.5;cursor:text;padding:2px 4px;border-radius:4px;" title="雙擊編輯">${escapeHtml(line)}</span>
                        <button type="button" class="add-tasks-remove btn btn-sm btn-outline-danger" data-idx="${i}" style="flex-shrink:0;padding:2px 8px;font-size:12px;">移除</button>
                    </div>
                `).join('')}
            </div>
        `;

        if (!window.Swal) {
            await doAddPointsToTasks(lines);
            return;
        }

        const result = await Swal.fire({
            title: '選擇待辦事項',
            html,
            width: 560,
            showCancelButton: true,
            confirmButtonText: '確定新增',
            cancelButtonText: '取消',
            reverseButtons: true,
            didOpen: (el) => {
                const list = el.querySelector(`#${listId}`);
                if (!list) return;
                list.querySelectorAll('.add-tasks-remove').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const item = list.querySelector(`.add-tasks-item[data-idx="${btn.getAttribute('data-idx')}"]`);
                        if (item) item.remove();
                    });
                });
                list.querySelectorAll('.add-tasks-text').forEach(span => {
                    span.addEventListener('dblclick', (e) => {
                        e.preventDefault();
                        span.setAttribute('contenteditable', 'true');
                        span.style.outline = '1px solid #94a3b8';
                        span.style.background = '#f8fafc';
                        span.focus();
                        const sel = window.getSelection();
                        const range = document.createRange();
                        range.selectNodeContents(span);
                        sel.removeAllRanges();
                        sel.addRange(range);
                    });
                    span.addEventListener('blur', () => {
                        span.removeAttribute('contenteditable');
                        span.style.outline = '';
                        span.style.background = '';
                    });
                });
            },
            preConfirm: () => {
                const list = document.getElementById(listId);
                if (!list) return [];
                const checked = list.querySelectorAll('.add-tasks-item .add-tasks-check:checked');
                return Array.from(checked).map(cb => {
                    const item = cb.closest('.add-tasks-item');
                    const text = item?.querySelector('.add-tasks-text');
                    return text ? text.textContent.trim() : '';
                }).filter(s => s !== '');
            }
        });

        if (!result.isConfirmed) return;
        const selected = result.value || [];
        if (selected.length === 0) {
            toast('warning', '請至少勾選一項');
            return;
        }
        await doAddPointsToTasks(selected);
    }

    async function doAddPointsToTasks(points) {
        setBusy(true, '新增待辦事項中...', '');
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    do: 'meeting_add_points_to_tasks',
                    m_ID: currentMeetingID,
                    team_ID: CURRENT_TEAM_ID || undefined,
                    points: points
                })
            });
            const data = await res.json();
            if (data.ok) {
                toast('success', data.msg || `已新增 ${data.tasks_count || 0} 筆待辦事項`);
            } else {
                toast('error', data.msg || '新增待辦事項失敗');
            }
        } catch (e) {
            console.error(e);
            toast('error', '連線或伺服器錯誤');
        } finally {
            setBusy(false);
        }
    }

    function renderSummary(summary, count) {
        // 保留函式避免舊呼叫報錯，AI 摘要改在 summary 分頁顯示，不再插入手寫內容。
        return;
    }

    async function handleCheckIn() {
        if (!ensureMeetingEditable()) return;
        try {
            const formData = new FormData();
            formData.append('do', 'check_in');
            if (currentMeetingID) formData.append('m_ID', currentMeetingID);
            if (CURRENT_TEAM_ID > 0) formData.append('team_ID', String(CURRENT_TEAM_ID));

            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await res.json();
            if (data.ok) {
                if (data.m_ID) currentMeetingID = data.m_ID;
                renderAttendance(data.list || []);
            } else {
                toast('error', data.msg);
            }
        } catch (e) { console.error(e); }
    }

    async function setAttendanceStatus(targetUid, status) {
        if (!ensureMeetingEditable()) return;
        try {
            const formData = new FormData();
            formData.append('do', 'set_attendance');
            formData.append('target_u_ID', targetUid);
            formData.append('status', status);
            if (currentMeetingID) formData.append('m_ID', currentMeetingID);
            if (CURRENT_TEAM_ID > 0) formData.append('team_ID', String(CURRENT_TEAM_ID));

            const res = await fetch(API_URL, { method: 'POST', body: formData });
            const data = await res.json();
            if (!data.ok) {
                toast('error', data.msg || '更新失敗');
                return;
            }
            await loadAttendance();
        } catch (e) {
            console.error(e);
            toast('error', '更新出席狀態失敗');
        }
    }

    async function loadAttendance() {
        if (elements.attendanceList) {
            elements.attendanceList.innerHTML = `<div style="font-size:12px;color:#64748b;padding:6px 2px;">點名資料載入中...</div>`;
        }
        try {
            const qs = new URLSearchParams({ do: 'get_attendance' });
            if (currentMeetingID) qs.set('id', String(currentMeetingID));
            if (CURRENT_TEAM_ID > 0) qs.set('team_ID', String(CURRENT_TEAM_ID));
            const url = `${API_URL}?${qs.toString()}`;
            const res = await fetch(url);
            const raw = await res.text();
            if (raw.trim().startsWith('<')) {
                if (elements.attendanceList) {
                    elements.attendanceList.innerHTML = `<div style="font-size:12px;color:#ef4444;padding:6px 2px;">點名載入失敗：API 回傳 HTML</div>`;
                }
                return;
            }
            const data = JSON.parse(raw);
            if (data.ok) {
                if (data.m_ID) currentMeetingID = data.m_ID;
                else currentMeetingID = null;
                meetingLockedRaw = Boolean(data.locked);
                meetingReopened = Boolean(data.reopened);
                applyMeetingLockState(data.locked, data.can_edit);
                renderAttendance(data.list, Boolean(data.no_meeting));
            } else if (elements.attendanceList) {
                elements.attendanceList.innerHTML = `<div style="font-size:12px;color:#ef4444;padding:6px 2px;">點名載入失敗：${escapeHtml(data.msg || '未知錯誤')}</div>`;
            }
        } catch (e) {
            console.error('loadAttendance error:', e);
            if (elements.attendanceList) {
                elements.attendanceList.innerHTML = `<div style="font-size:12px;color:#ef4444;padding:6px 2px;">點名載入失敗：${escapeHtml(String(e.message || e))}</div>`;
            }
        }
    }
    function renderAttendance(list, noMeeting) {
        if (!elements.attendanceList) return;
        const people = Array.isArray(list) ? list : [];
        if (elements.attendanceStats) elements.attendanceStats.innerHTML = '';
        if (!people.length) {
            elements.attendanceList.innerHTML = `<div style="font-size:12px;color:#64748b;padding:6px 2px;">目前沒有可顯示的點名名單</div>`;
            return;
        }

        const presentCount = people.filter(item => String(item.self_check || 'no') === 'ok').length;
        const absentCount = Math.max(people.length - presentCount, 0);
        const rate = people.length ? Math.round((presentCount / people.length) * 100) : 0;
        if (elements.attendanceStats) {
            elements.attendanceStats.innerHTML = `
                <span class="attendance-stat attendance-stat--present"><span class="attendance-stat-badge"></span>${presentCount} 出席</span>
                <span class="attendance-stat attendance-stat--absent"><span class="attendance-stat-badge"></span>${absentCount} 未到</span>
                <span class="attendance-stat attendance-stat--rate">出席率：${rate}%</span>
            `;
        }

        const rowsHtml = people.map(item => {
            const uid = String(item.uid || '');
            const name = String(item.u_name || uid || '未命名');
            const isAttend = String(item.self_check || 'no') === 'ok';
            return `
            <div class="attendance-row" data-uid="${escapeHtml(uid)}">
                <div class="attendance-cell attendance-name">${escapeHtml(name)}</div>
                <div class="attendance-cell attendance-status">
                    <button type="button" class="attendance-btn attendance-btn--ok ${isAttend ? 'is-active' : ''}" data-status="ok" ${meetingLocked ? 'disabled' : ''}>出席</button>
                    <button type="button" class="attendance-btn attendance-btn--no ${!isAttend ? 'is-active' : ''}" data-status="no" ${meetingLocked ? 'disabled' : ''}>未到</button>
                </div>
            </div>
            `;
        }).join('');

        const noMeetingHint = noMeeting ? `<div style="font-size:11px;color:#94a3b8;padding:6px 0;margin-top:4px;">尚無會議</div>` : '';
        elements.attendanceList.innerHTML = `
            <div class="attendance-table">
                <div class="attendance-table-head">
                    <span class="attendance-th">姓名</span>
                    <span class="attendance-th">狀態</span>
                </div>
                ${rowsHtml}
            </div>
            ${noMeetingHint}
        `;

        elements.attendanceList.querySelectorAll('.attendance-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const row = btn.closest('.attendance-row');
                if (!row) return;
                const uid = row.getAttribute('data-uid');
                const status = btn.getAttribute('data-status');
                if (!uid || !status) return;
                setAttendanceStatus(uid, status);
            });
        });
    }


    // UI 輔助
    function toggleRecordingUI(active) {
        if (elements.recordingStatus) elements.recordingStatus.style.display = active ? 'flex' : 'none';
        if (elements.recordBtn) elements.recordBtn.style.display = active ? 'none' : 'flex';
    }

    function startTimer() {
        startTime = Date.now();
        recordingTimer = setInterval(() => {
            const sec = Math.floor((Date.now() - startTime) / 1000);
            if (elements.recordingTime) elements.recordingTime.innerText =
                `${Math.floor(sec / 60).toString().padStart(2, '0')}:${(sec % 60).toString().padStart(2, '0')}`;
        }, 1000);
    }
    function stopTimer() { clearInterval(recordingTimer); }

    function addSystemMessage(msg, isTemp) {
        const p = document.createElement('p');
        p.innerText = msg;
        p.style.color = '#888';
        p.style.fontStyle = 'italic';
        if (isTemp) p.id = 'temp-msg';
        elements.meetingText.appendChild(p);
    }
    function removeSystemMessage() {
        const el = document.getElementById('temp-msg');
        if (el) el.remove();
    }
    function formatDateYmd(d) {
        if (!d) return null;
        const s = String(d).trim();
        if (!s) return null;
        try {
            const dt = new Date(s);
            if (isNaN(dt.getTime())) return null;
            return dt.getFullYear() + '/' + String(dt.getMonth() + 1).padStart(2, '0') + '/' + String(dt.getDate()).padStart(2, '0');
        } catch (_) { return null; }
    }
    function formatDateYmdHm(d) {
        if (!d) return null;
        const s = String(d).trim();
        if (!s) return null;
        try {
            const dt = new Date(s);
            if (isNaN(dt.getTime())) return null;
            return formatDateYmd(d) + ' ' + String(dt.getHours()).padStart(2, '0') + ':' + String(dt.getMinutes()).padStart(2, '0');
        } catch (_) { return null; }
    }
    function updateDateBadges(data) {
        const startStr = formatDateYmd(data?.m_start_d) || '—';
        const createdStr = formatDateYmdHm(data?.m_created_d) || formatDateYmd(data?.m_created_d) || '—';
        if (elements.dateBadgeStart) elements.dateBadgeStart.textContent = '會議：' + startStr;
        if (elements.dateBadgeCreated) elements.dateBadgeCreated.textContent = '建立：' + createdStr;
    }
    function applyNoMeetingState(noMeeting) {
        if (elements.appContainer) {
            elements.appContainer.classList.toggle('is-no-meeting', !!noMeeting);
        }
        if (noMeeting) {
            stopEditStatusPolling();
            updateDateBadges(null);
            const historyBody = document.getElementById('historyBody');
            if (historyBody) historyBody.style.display = 'block';
        }
    }

    async function clearContent(kind = currentContentKind, mrId = null) {
        if (!ensureMeetingEditable()) return;
        const root = elements.meetingText;
        const inEditNote = root && root.classList.contains('is-editing-record');
        const inEditSummary = root && root.classList.contains('is-editing-summary');
        const inEditRecord = root && root.classList.contains('is-editing-record-content');
        const contentMrId = root ? parseInt(root.querySelector('.content-kind-item-text[data-mr-id], .content-transcript-segments[data-mr-id]')?.getAttribute('data-mr-id') || '0', 10) : 0;
        const canClear = (kind === 'note' && inEditNote) || (kind === 'summary' && inEditSummary) || ((kind === 'text' || kind === 'image' || kind === 'audio') && inEditRecord && contentMrId === (mrId || 0));
        if (!canClear) {
            toast('warning', '請先按下「編輯」進入編輯模式後才能清除或刪除內容');
            return;
        }
        const isSingleDelete = mrId != null && mrId > 0 && kind !== 'summary';
        const confirmMsg = isSingleDelete ? '確定要刪除此筆檔案？' : '確定清除此工作區的內容？';
        if (!(await confirmSwal('確認清除', confirmMsg))) return;

        if (isSingleDelete) {
            setBusy(true, '刪除中...', '請稍候');
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        do: 'meeting_delete_record',
                        m_ID: currentMeetingID,
                        mr_ID: mrId,
                        team_ID: CURRENT_TEAM_ID || undefined
                    })
                });
                const data = await res.json();
                if (!data.ok) {
                    toast('error', data.msg || '刪除失敗');
                    return;
                }
                const items = (currentMeetingFilesByKind[kind] || []).filter(it => (it.id || 0) !== mrId);
                currentMeetingFilesByKind[kind] = items;
                currentContentCounts[kind] = items.length;
                const tabs = document.querySelectorAll('.content-type-tab[data-kind]');
                tabs.forEach((tab) => {
                    if (tab.getAttribute('data-kind') === kind) {
                        const ce = tab.querySelector('.content-type-count');
                        if (ce) ce.textContent = String(items.length);
                    }
                });
                setUnsavedContentState(true);
                renderContentKindPanel(kind);
            } finally {
                setBusy(false);
            }
        } else {
            pendingClear[kind] = true;
            setUnsavedContentState(true);
            if (kind === 'note') noteDraftCache = '';
            currentMeetingFilesByKind[kind] = [];
            currentContentCounts[kind] = 0;
            const tabs = document.querySelectorAll('.content-type-tab[data-kind]');
            tabs.forEach((tab) => {
                if (tab.getAttribute('data-kind') === kind) {
                    const ce = tab.querySelector('.content-type-count');
                    if (ce) ce.textContent = '0';
                }
            });
            renderContentKindPanel(kind);
        }
    }

    function updateCreateModalFilePickerDisplay(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const files = input.files || [];
        const n = files.length;
        const zone = input.closest('.file-picker-zone');
        const infoId = inputId.replace('Files', 'Info');
        const info = document.getElementById(infoId);
        if (zone) zone.classList.toggle('has-files', n > 0);
        if (info) {
            info.classList.toggle('has-files', n > 0);
            info.textContent = n > 0 ? `已選擇 ${n} 個檔案` : (info.getAttribute('data-placeholder') || '可多選');
        }
    }
    function openCreateMeetingModal() {
        if (elements.createMeetingModal) {
            elements.createMeetingModal.style.display = 'flex';
            if (elements.newMeetingTitleInput) {
                elements.newMeetingTitleInput.value = '';
                elements.newMeetingTitleInput.focus();
            }
            if (elements.newMeetingDateInput) {
                const today = new Date().toISOString().slice(0, 10);
                elements.newMeetingDateInput.value = today;
            }
            if (elements.createModalNoteText) elements.createModalNoteText.value = '';
            if (elements.createModalTextFiles) { elements.createModalTextFiles.value = ''; updateCreateModalFilePickerDisplay('createModalTextFiles'); }
            if (elements.createModalImageFiles) { elements.createModalImageFiles.value = ''; updateCreateModalFilePickerDisplay('createModalImageFiles'); }
            if (elements.createModalAudioFiles) { elements.createModalAudioFiles.value = ''; updateCreateModalFilePickerDisplay('createModalAudioFiles'); }
            if (elements.createModalAiSummarize) elements.createModalAiSummarize.checked = false;
        }
    }
    function closeCreateMeetingModal() {
        if (elements.createMeetingModal) elements.createMeetingModal.style.display = 'none';
    }
    async function submitCreateMeeting() {
        const title = elements.newMeetingTitleInput ? elements.newMeetingTitleInput.value.trim() : '';
        if (!title) {
            toast('warning', '請輸入會議名稱');
            return;
        }
        const mDate = elements.newMeetingDateInput ? elements.newMeetingDateInput.value : '';
        const noteText = elements.createModalNoteText ? elements.createModalNoteText.value.trim() : '';
        const textFiles = elements.createModalTextFiles?.files ? Array.from(elements.createModalTextFiles.files) : [];
        const imageFiles = elements.createModalImageFiles?.files ? Array.from(elements.createModalImageFiles.files) : [];
        const audioFiles = elements.createModalAudioFiles?.files ? Array.from(elements.createModalAudioFiles.files) : [];
        const doAiSummarize = elements.createModalAiSummarize ? elements.createModalAiSummarize.checked : false;

        closeCreateMeetingModal();
        setBusy(true, '建立會議中...', '請稍候');

        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    do: 'create_meeting',
                    team_ID: CURRENT_TEAM_ID || undefined,
                    title: title,
                    m_date: mDate || undefined
                })
            });
            const data = await res.json();
            if (!data.ok || !data.m_ID) {
                toast('error', data.msg || '建立失敗');
                return;
            }

            currentMeetingID = data.m_ID;
            lastKnownLatestMid = data.m_ID;
            applyNoMeetingState(false);
            updateDateBadges(data);
            if (elements.meetingTitle) elements.meetingTitle.innerText = title;
            noteDraftCache = '';
            noteDraftName = '打字紀錄';
            currentFileIndexByKind = {}; // 切換到新會議時重置分頁
            if (elements.meetingText) {
                elements.meetingText.classList.remove('is-history-mode');
                elements.meetingText.setAttribute('contenteditable', 'false');
                setActiveContentTab('note');
            }
            // 立即載入新會議的內容工作區與出席紀錄，避免顯示舊會議資料
            await refreshContentTypeCounts(currentMeetingID);
            await loadAttendance();
            if (elements.meetingText) renderContentKindPanel('note');
            setUnsavedContentState(false);

            if (noteText) {
                setBusy(true, '儲存打字紀錄...', '請稍候');
                try {
                    const saveRes = await fetch(API_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            do: 'meeting_note_save',
                            m_ID: currentMeetingID,
                            team_ID: CURRENT_TEAM_ID || undefined,
                            content: noteText,
                            mr_name: '手寫筆記'
                        })
                    });
                    const saveData = await saveRes.json();
                    if (saveData.ok) {
                        await refreshContentTypeCounts(currentMeetingID);
                    }
                } catch (e) { console.error(e); }
            }

            for (let i = 0; i < textFiles.length; i++) {
                const file = textFiles[i];
                setBusy(true, '上傳文字檔...', `(${i + 1}/${textFiles.length}) ${file.name}`);
                try {
                    const fd = new FormData();
                    fd.append('do', 'meeting_upload_text');
                    fd.append('file', file);
                    fd.append('m_ID', currentMeetingID);
                    if (CURRENT_TEAM_ID) fd.append('team_ID', CURRENT_TEAM_ID);
                    const r = await fetch(API_URL, { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.ok) await refreshContentTypeCounts(currentMeetingID);
                } catch (e) { console.error(e); toast('error', '文字檔上傳失敗：' + file.name); }
            }

            for (let i = 0; i < imageFiles.length; i++) {
                const file = imageFiles[i];
                setBusy(true, '圖片 OCR 中...', `(${i + 1}/${imageFiles.length}) ${file.name}`);
                try {
                    const fd = new FormData();
                    fd.append('do', 'meeting_ocr_image');
                    fd.append('file', file);
                    fd.append('m_ID', currentMeetingID);
                    if (CURRENT_TEAM_ID) fd.append('team_ID', CURRENT_TEAM_ID);
                    const r = await fetch(API_URL, { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.ok) await refreshContentTypeCounts(currentMeetingID);
                } catch (e) { console.error(e); toast('error', '圖片 OCR 失敗：' + file.name); }
            }

            for (let i = 0; i < audioFiles.length; i++) {
                const file = audioFiles[i];
                setBusy(true, '語音轉錄中...', `(${i + 1}/${audioFiles.length}) ${file.name}`);
                try {
                    const fd = new FormData();
                    fd.append('do', 'meeting_transcribe_audio');
                    fd.append('file', file);
                    fd.append('diarize', '1');
                    fd.append('m_ID', currentMeetingID);
                    if (CURRENT_TEAM_ID) fd.append('team_ID', CURRENT_TEAM_ID);
                    const r = await fetch(API_URL, { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.ok) await refreshContentTypeCounts(currentMeetingID);
                } catch (e) { console.error(e); toast('error', '語音轉錄失敗：' + file.name); }
            }

            if (doAiSummarize) {
                setBusy(true, 'AI 統整中...', '請稍候');
                try {
                    const sumRes = await fetch(API_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            do: 'meeting_summarize',
                            m_ID: currentMeetingID,
                            team_ID: CURRENT_TEAM_ID || undefined,
                            use_server_content: true
                        })
                    });
                    const sumData = await sumRes.json();
                    if (sumData.ok) await refreshContentTypeCounts(currentMeetingID);
                    else if (sumData.msg) toast('warning', sumData.msg);
                } catch (e) { console.error(e); toast('error', 'AI 統整失敗'); }
            }

            await refreshContentTypeCounts(currentMeetingID);
            await openMeetingFiles(currentMeetingID);
            if (doAiSummarize) {
                setActiveContentTab('summary');
                renderContentKindPanel('summary');
            } else {
                renderContentKindPanel(currentContentKind);
            }
            loadAttendance();
            loadMeetingHistory();
        } catch (e) {
            console.error(e);
            toast('error', '建立會議失敗');
        } finally {
            setBusy(false);
        }
    }

    async function deleteMeeting() {
        if (!currentMeetingID) {
            toast('info', '尚無會議可刪除');
            return;
        }
        if (meetingLocked) {
            toast('warning', '此會議已確認，無法刪除');
            return;
        }
        if (!(await confirmSwal('確認刪除', '確定要刪除此會議？此操作無法復原。'))) return;

        setBusy(true, '刪除中...', '正在刪除會議');
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    do: 'delete_meeting',
                    m_ID: currentMeetingID,
                    team_ID: CURRENT_TEAM_ID || undefined
                })
            });
            const data = await res.json();
            if (data.ok) {
                currentMeetingID = null;
                if (elements.meetingText) elements.meetingText.innerText = '';
                if (elements.meetingTitle) elements.meetingTitle.innerText = '尚無會議';
                setUnsavedContentState(false);
                loadMeetingData();
                loadAttendance();
                loadMeetingHistory();
                toast('success', data.msg || '已刪除會議');
            } else {
                toast('error', data.msg || '刪除失敗');
            }
        } catch (e) {
            console.error(e);
            toast('error', '刪除會議失敗');
        } finally {
            setBusy(false);
        }
    }

    // --- 立即執行初始化 ---
    initMeetingPage();

})();