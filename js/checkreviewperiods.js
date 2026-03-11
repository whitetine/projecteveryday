(function(){
// 移除重複載入檢查，允許 SPA 頁面切換時重新初始化

// 動態解析 API URL（支援動態載入）
function resolveCheckReviewPeriodsApiUrl() {
  const path = window.location.pathname || '';
  if (path.includes('/pages/')) {
    return 'checkreviewperiods_data.php';
  }
  return 'pages/checkreviewperiods_data.php';
}

let pendingClassValue = '';
const teamPickerState = {
  enabled: true,
  mode: 'in',
  list: [],
  selectedIds: [],
  receiveIds: [],
  summaryMessage: '請先選擇屆別',
  receiveSummaryMessage: '請先選擇屆別',
  dualMode: false,
  modalSelections: [],
  receiveModalSelections: [],
  pendingSelections: [],
  pendingReceiveSelections: [],
  activePanel: 'assign'
};

function __initCheckReviewPeriods() {
  // 動態設定表單 action
  const form = document.getElementById('periodForm');
  if (form) {
    // 檢查是否已經初始化過（避免重複綁定）
    if (form.dataset.initialized === 'true') {
      return; // 已經初始化過，跳過
    }
    form.dataset.initialized = 'true';
    form.action = resolveCheckReviewPeriodsApiUrl();
    // 攔截表單提交，改用 AJAX
    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      // 驗證開始時間和結束時間
      const startInput = document.getElementById('period_start_d');
      const endInput = document.getElementById('period_end_d');
      if (startInput && endInput && startInput.value && endInput.value) {
        const startTime = new Date(startInput.value);
        const endTime = new Date(endInput.value);
        if (isNaN(startTime.getTime()) || isNaN(endTime.getTime())) {
          const errorMsg = '開始時間或結束時間格式錯誤';
          if (window.Swal) {
            Swal.fire('錯誤', errorMsg, 'error');
          } else {
            alert(errorMsg);
          }
          return;
        }
        if (endTime <= startTime) {
          const errorMsg = '結束時間必須晚於開始時間';
          if (window.Swal) {
            Swal.fire('錯誤', errorMsg, 'error');
          } else {
            alert(errorMsg);
          }
          // 聚焦到結束時間輸入框
          endInput.focus();
          return;
        }
      }
      
      const formData = new FormData(form);
      const action = formData.get('action');
      
      try {
        const res = await fetch(resolveCheckReviewPeriodsApiUrl(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: formData
        });
        
        if (res.ok) {
          const result = await res.json();
          if (result.success) {
            // 成功後重新載入表格並清空表單
            await loadPeriodTable();
            resetForm();
            // 顯示成功訊息
            if (window.Swal) {
              Swal.fire({
                title: '成功',
                text: result.msg || (action === 'create' ? '已新增評分時段' : '已更新評分時段'),
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
              });
            }
          } else {
            throw new Error(result.msg || '操作失敗');
          }
        } else {
          throw new Error('HTTP ' + res.status);
        }
      } catch (err) {
        console.error('提交失敗:', err);
        if (window.Swal) {
          Swal.fire('錯誤', '操作失敗：' + err.message, 'error');
        } else {
          alert('操作失敗：' + err.message);
        }
      }
    });
  }
  
  try { setupCohortSelect(); } catch (e) { console.error(e); }
  try { setupClassSelect(); } catch (e) { console.error(e); }
  try { loadCohortList(); } catch (e) { console.error(e); }
  try { loadClassList(); } catch (e) { console.error(e); }
  try { loadPeriodTable(); } catch (e) { console.error(e); }
  try { setupModeSelector(); } catch (e) { console.error(e); }
  try { setupTeamPicker(); } catch (e) { console.error(e); }
  try { setupPeriodTableDelegation(); } catch (e) { console.error(e); }
}
// 初始化函數
function initCheckReviewPeriods() {
  // 檢查必要元素是否存在
  if (!document.getElementById('periodForm') && !document.getElementById('cohortSelect')) {
    return; // 頁面尚未載入，稍後再試
  }
  __initCheckReviewPeriods();
}

// 立即執行（如果 DOM 已準備好）
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initCheckReviewPeriods);
} else {
  initCheckReviewPeriods();
}

// 暴露到 window，供 SPA 頁面切換時呼叫
window.initCheckReviewPeriods = initCheckReviewPeriods;

// 監聽頁面載入完成事件（SPA 使用）
(function setupSPAObserver() {
  let initTimer = null;
  // 使用 MutationObserver 監聽內容區域變化
  const contentArea = document.querySelector('#content, .content, main');
  if (contentArea && contentArea instanceof Node) {
    try {
      const observer = new MutationObserver(function(mutations) {
        // 當內容區域有變化時，檢查是否需要初始化（使用 debounce 避免頻繁觸發）
        if (document.getElementById('periodForm') || document.getElementById('cohortSelect')) {
          clearTimeout(initTimer);
          initTimer = setTimeout(function() {
            initCheckReviewPeriods();
          }, 150);
        }
      });
      observer.observe(contentArea, {
        childList: true,
        subtree: true
      });
    } catch (e) {
      console.warn('無法觀察內容區域:', e);
    }
    
    // 立即檢查一次（如果頁面已經載入）
    if (document.getElementById('periodForm') || document.getElementById('cohortSelect')) {
      setTimeout(initCheckReviewPeriods, 100);
    }
  } else {
    // 如果內容區域尚未載入，等待 DOMContentLoaded
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setupSPAObserver);
    } else {
      // DOM 已準備好，但內容區域可能尚未載入，稍後再試
      setTimeout(setupSPAObserver, 500);
    }
  }
})();

function setupCohortSelect() {
  const select = document.getElementById('cohortSelect');
  if (!select) return;
  select.addEventListener('change', () => handleCohortChange(true));
  
  // 添加點擊事件，允許直接點擊已選中的選項來取消選擇
  select.addEventListener('mousedown', (e) => {
    const option = e.target;
    if (option.tagName !== 'OPTION' || !option.value) return;
    
    // 如果選項已經被選中，且沒有按住 Ctrl/Cmd/Shift 鍵，則取消選擇
    if (option.selected && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
      e.preventDefault();
      option.selected = false;
      // 觸發 change 事件以更新相關狀態
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
}

function setupClassSelect() {
  const select = document.getElementById('classSelect');
  if (!select) return;
  select.addEventListener('change', () => handleClassChange(true));
  
  // 添加點擊事件，允許直接點擊已選中的選項來取消選擇
  select.addEventListener('mousedown', (e) => {
    const option = e.target;
    if (option.tagName !== 'OPTION' || !option.value) return;
    
    // 如果選項已經被選中，且沒有按住 Ctrl/Cmd/Shift 鍵，則取消選擇
    if (option.selected && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
      e.preventDefault();
      option.selected = false;
      // 觸發 change 事件以更新相關狀態
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
}

function getSelectedCohortValues() {
  const select = document.getElementById('cohortSelect');
  if (!select) return [];
  return Array.from(select.selectedOptions)
    .map(option => option.value)
    .filter(Boolean);
}

function handleCohortChange(triggerLoad = true) {
  const selected = getSelectedCohortValues();
  const valuesInput = document.getElementById('cohort_values');
  if (valuesInput) valuesInput.value = selected.join(',');
  const primaryInput = document.getElementById('cohort_primary');
  if (primaryInput) primaryInput.value = selected[0] || '';
  if (triggerLoad) {
    loadTeamList(selected, getSelectedClassValues());
  }
}

function setSelectedCohorts(values, triggerLoad = true) {
  const select = document.getElementById('cohortSelect');
  if (!select) return;
  const arr = Array.isArray(values) ? values.map(String) : (values ? [String(values)] : []);
  Array.from(select.options).forEach(option => {
    option.selected = arr.includes(option.value);
  });
  handleCohortChange(triggerLoad);
}

function renderCohortOptions(list) {
  const select = document.getElementById('cohortSelect');
  if (!select) return;

  if (!Array.isArray(list) || !list.length) {
    select.innerHTML = '<option value="">尚無可選屆別</option>';
    select.disabled = true;
    handleCohortChange(false);
    return;
  }

  select.disabled = false;
  select.innerHTML = '';
  list.forEach(c => {
    const labelText = `${c.cohort_name} (${c.year_label})`;
    const option = document.createElement('option');
    option.value = c.cohort_ID;
    option.textContent = labelText;
    select.appendChild(option);
  });
}

function handleClassChange(triggerLoad = true) {
  const selected = getSelectedClassValues();
  const primaryInput = document.getElementById('class_primary');
  // 提交所有選中的班級ID（用逗號分隔），以便後端可以處理多個班級
  if (primaryInput) primaryInput.value = selected.join(',');
  if (triggerLoad) {
    loadTeamList(getSelectedCohortValues(), selected);
  }
}

function renderClassOptions(list) {
  const select = document.getElementById('classSelect');
  if (!select) return;

  select.innerHTML = '';
  if (!Array.isArray(list) || !list.length) {
    const noDataOption = document.createElement('option');
    noDataOption.value = '';
    noDataOption.textContent = '尚無班級資料';
    select.appendChild(noDataOption);
    select.disabled = true;
    pendingClassValue = '';
    return;
  }

  select.disabled = false;
  list.forEach(cls => {
    const option = document.createElement('option');
    option.value = cls.c_ID || cls.class_ID || '';
    option.textContent = cls.c_name || cls.class_name || '';
    select.appendChild(option);
  });

  if (pendingClassValue) {
    const hasPending = Array.from(select.options).some(opt => opt.value === pendingClassValue);
    if (hasPending) {
      setClassSelections([pendingClassValue], false);
      pendingClassValue = '';
    }
  }
}

function loadClassList() {
  const apiUrl = resolveCheckReviewPeriodsApiUrl();
  fetch(`${apiUrl}?class_list=1`)
    .then(r => r.json())
    .then(list => {
      renderClassOptions(list);
    })
    .catch(err => {
      console.error('載入班級失敗:', err);
      const select = document.getElementById('classSelect');
      if (select) {
        select.innerHTML = '<option value="">班級載入失敗</option>';
        select.disabled = true;
      }
    });
}

function setClassSelections(values, triggerLoad = false) {
  const select = document.getElementById('classSelect');
  const arr = Array.isArray(values)
    ? values.map(String).filter(Boolean)
    : (values ? [String(values)] : []);
  const primaryInput = document.getElementById('class_primary');
  // 提交所有選中的班級ID（用逗號分隔）
  if (primaryInput) primaryInput.value = arr.join(',');
  if (!select) {
    pendingClassValue = arr[0] || '';
    return;
  }
  Array.from(select.options).forEach(option => {
    option.selected = arr.includes(option.value);
  });
  if (triggerLoad) {
    loadTeamList(getSelectedCohortValues(), arr);
  }
}

function getSelectedClassValues() {
  const select = document.getElementById('classSelect');
  if (!select) return [];
  return Array.from(select.selectedOptions)
    .map(option => option.value)
    .filter(Boolean);
}

function parseTeamIdList(raw) {
  if (!raw || raw === 'ALL') return [];
  if (Array.isArray(raw)) {
    return raw.map(String).filter(Boolean);
  }
  return String(raw)
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);
}

function parseTeamAssignmentPayload(raw) {
  if (!raw || raw === 'ALL') {
    return { assign: [], receive: [] };
  }
  if (typeof raw === 'string' && raw.trim().startsWith('{')) {
    try {
      const data = JSON.parse(raw);
      return {
        assign: Array.isArray(data.assign) ? data.assign.map(String).filter(Boolean) : [],
        receive: Array.isArray(data.receive) ? data.receive.map(String).filter(Boolean) : []
      };
    } catch (err) {
      console.warn('解析團隊 JSON 失敗，回退為舊格式', err);
    }
  }
  const list = parseTeamIdList(raw);
  return { assign: list, receive: [] };
}

/* 載入屆別 */
function loadCohortList() {
  const apiUrl = resolveCheckReviewPeriodsApiUrl();
  fetch(`${apiUrl}?cohort_list=1`)
      .then(r => r.json())
      .then(list => {
          renderCohortOptions(list);
          const presetValues = (document.getElementById('cohort_values')?.value || '')
            .split(',')
            .filter(Boolean);
          const primary = document.getElementById('cohort_primary')?.value || '';
          const initial = presetValues.length ? presetValues : (primary ? [primary] : []);
          setSelectedCohorts(initial, false);
          handleCohortChange(true);
      })
      .catch(err => {
          console.error('載入屆別失敗:', err);
          const select = document.getElementById('cohortSelect');
          if (select) {
            select.innerHTML = '<option value="">屆別載入失敗</option>';
            select.disabled = true;
          }
      });
}

/* 載入團隊：依屆別/班級 */
function loadTeamList(cohortId, classId, preselectTeams) {
  const ids = Array.isArray(cohortId)
    ? cohortId.filter(Boolean)
    : (cohortId ? [cohortId] : []);
  const classIds = Array.isArray(classId)
    ? classId.filter(Boolean)
    : (classId ? [classId] : []);
  let desiredAssign = null;
  let desiredReceive = null;
  if (preselectTeams !== undefined) {
    if (preselectTeams && typeof preselectTeams === 'object' && !Array.isArray(preselectTeams)) {
      desiredAssign = Array.isArray(preselectTeams.assign) ? preselectTeams.assign : [];
      desiredReceive = Array.isArray(preselectTeams.receive) ? preselectTeams.receive : [];
    } else {
      desiredAssign = parseTeamIdList(preselectTeams);
      desiredReceive = [];
    }
  }
  if (!ids.length) {
    teamPickerState.list = [];
    teamPickerState.summaryMessage = '請先選擇屆別';
    teamPickerState.receiveSummaryMessage = '請先選擇屆別';
    setTeamSelections([], { role: 'assign', keepMessage: true });
    setTeamSelections([], { role: 'receive', keepMessage: true });
    updateTeamSummaryDisplay();
    return;
  }
  const apiUrl = resolveCheckReviewPeriodsApiUrl();
  const params = new URLSearchParams();
  params.set('team_list', '1');
  params.set('cohort_id', ids.join(','));
  if (classIds.length) {
    params.set('class_id', classIds.join(','));
  }
  teamPickerState.summaryMessage = '載入團隊中...';
  updateTeamSummaryDisplay();
  fetch(`${apiUrl}?${params.toString()}`)
    .then(r => {
      console.log('團隊列表請求 URL:', `${apiUrl}?${params.toString()}`);
      console.log('團隊列表響應狀態:', r.status, r.statusText);
      return r.json();
    })
    .then(list => {
      console.log('團隊列表原始數據:', list);
      console.log('團隊列表是否為數組:', Array.isArray(list));
      teamPickerState.list = Array.isArray(list) ? list : [];
      console.log('teamPickerState.list 長度:', teamPickerState.list.length);
      if (!teamPickerState.list.length) {
        console.log('團隊列表為空，顯示「無符合條件的團隊」');
        teamPickerState.summaryMessage = '無符合條件的團隊';
        teamPickerState.receiveSummaryMessage = '無符合條件的團隊';
        setTeamSelections([], { role: 'assign', keepMessage: true });
        setTeamSelections([], { role: 'receive', keepMessage: true });
      } else {
        console.log('找到', teamPickerState.list.length, '個團隊:', teamPickerState.list);
        const availableIds = new Set(teamPickerState.list.map(t => String(t.team_ID)));

        const resolveSelection = (current, pending, desired) => {
          let result = Array.isArray(desired) && desired.length ? desired : [...current];
          result = result.filter(id => availableIds.has(String(id)));
          const resolvedPending = (pending || []).filter(id => availableIds.has(String(id)));
          return [...new Set([...result, ...resolvedPending])];
        };

        const nextAssign = resolveSelection(teamPickerState.selectedIds, teamPickerState.pendingSelections, desiredAssign);
        const nextReceive = resolveSelection(teamPickerState.receiveIds, teamPickerState.pendingReceiveSelections, desiredReceive);

        const hasAssign = nextAssign.length > 0;
        const hasReceive = nextReceive.length > 0;

        setTeamSelections(nextAssign, { role: 'assign', keepMessage: hasAssign });
        setTeamSelections(nextReceive, { role: 'receive', keepMessage: hasReceive });

        teamPickerState.summaryMessage = hasAssign ? '' : '請選擇團隊';
        teamPickerState.receiveSummaryMessage = hasReceive ? '' : '請選擇被評分團隊';
      }
      updateTeamSummaryDisplay();
      renderTeamModalLists();
    })
    .catch(err => {
        console.error('載入團隊失敗:', err);
        teamPickerState.list = [];
        teamPickerState.summaryMessage = '載入團隊失敗';
        teamPickerState.receiveSummaryMessage = '載入團隊失敗';
        setTeamSelections([], { role: 'assign', keepMessage: true });
        setTeamSelections([], { role: 'receive', keepMessage: true });
        updateTeamSummaryDisplay();
    });
}

/* 模式選擇 */
function setupModeSelector() {
  const labelEl = document.getElementById('modeLabel');
  const hiddenEl = document.getElementById('mode_value');
  const dropdownEl = document.getElementById('modeDropdown');
  const hintEl = document.getElementById('modeHint');
  const dropdownInstance = (dropdownEl && window.bootstrap)
    ? bootstrap.Dropdown.getOrCreateInstance(dropdownEl)
    : null;

  const applyMode = (btn) => {
    if (!btn) return;
    const mode = btn.dataset.mode || '';
    const text = btn.textContent.trim() || '請選擇模式';
    const hint = btn.dataset.hint || '';
    if (labelEl) labelEl.textContent = text;
    if (hiddenEl) hiddenEl.value = mode;
    if (hintEl) hintEl.textContent = hint || ' ';
    dropdownInstance?.hide();
    applyTeamPickerMode(mode);
  };

  document.querySelectorAll('.mode-option').forEach(btn => {
    btn.addEventListener('click', () => applyMode(btn));
  });

  const initialValue = hiddenEl?.value || 'in';
  const initialBtn =
    document.querySelector(`.mode-option[data-mode="${initialValue}"]`) ||
    document.querySelector('.mode-option');
  applyMode(initialBtn);
}

function setupTeamPicker() {
  const trigger = document.getElementById('teamPickerTrigger');
  const clearBtn = document.getElementById('teamPickerClear');
  const receiveTrigger = document.getElementById('receivePickerTrigger');
  const receiveClearBtn = document.getElementById('receivePickerClear');
  const modal = document.getElementById('teamPickerModal');
  const closeBtn = document.getElementById('teamPickerClose');
  const cancelBtn = document.getElementById('teamPickerCancel');
  const saveBtn = document.getElementById('teamPickerSave');
  const dualToggleBtn = document.getElementById('teamPickerDualToggle');
  const mirrorBtn = document.getElementById('teamPickerMirror');

  if (trigger) {
    trigger.addEventListener('click', () => openTeamPicker('assign'));
    trigger.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openTeamPicker('assign');
      }
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      setTeamSelections([], { role: 'assign', forceAllLabel: true });
    });
  }
  if (receiveTrigger) {
    receiveTrigger.addEventListener('click', () => {
      if (teamPickerState.mode !== 'cross') return;
      openTeamPicker('receive');
    });
    receiveTrigger.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (teamPickerState.mode !== 'cross') return;
        openTeamPicker('receive');
      }
    });
  }
  if (receiveClearBtn) {
    receiveClearBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      setTeamSelections([], { role: 'receive', forceAllLabel: true });
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', closeTeamPicker);
  if (cancelBtn) cancelBtn.addEventListener('click', closeTeamPicker);
  if (saveBtn) saveBtn.addEventListener('click', () => {
    const selections = teamPickerState.modalSelections || [];
    const receiveSelections = (teamPickerState.mode === 'cross')
      ? (teamPickerState.dualMode ? (teamPickerState.receiveModalSelections || []) : teamPickerState.receiveIds || [])
      : [];
    const forceAllAssign = selections.length === 0;
    const forceAllReceive = receiveSelections.length === 0;
    setTeamSelections(selections, { role: 'assign', forceAllLabel: forceAllAssign });
    if (teamPickerState.mode === 'cross') {
      setTeamSelections(receiveSelections, { role: 'receive', forceAllLabel: forceAllReceive });
    }
    syncTeamHiddenValue();
    closeTeamPicker();
  });
  if (dualToggleBtn) {
    dualToggleBtn.addEventListener('click', () => {
      if (teamPickerState.mode !== 'cross') return;
      teamPickerState.dualMode = !teamPickerState.dualMode;
      renderTeamModalLists();
      updateModalLayout();
    });
  }
  if (mirrorBtn) {
    mirrorBtn.addEventListener('click', () => {
      if (!teamPickerState.dualMode) {
        teamPickerState.dualMode = true;
        updateModalLayout();
      }
      teamPickerState.receiveModalSelections = [...(teamPickerState.modalSelections || [])];
      renderModalSelectedDisplay('receive');
      renderTeamModalList('receive');
      updateMirrorButtonState();
    });
  }
  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeTeamPicker();
    });
  }
  updateTeamSummaryDisplay();
  updateModalLayout();
}

function updateMirrorButtonState() {
  const mirrorBtn = document.getElementById('teamPickerMirror');
  if (!mirrorBtn) return;
  const canMirror = teamPickerState.mode === 'cross'
    && teamPickerState.dualMode
    && Array.isArray(teamPickerState.modalSelections)
    && teamPickerState.modalSelections.length > 0;
  mirrorBtn.disabled = !canMirror;
}

function updateModalLayout() {
  const modal = document.getElementById('teamPickerModal');
  if (!modal) return;
  const dialog = modal.querySelector('.team-picker-dialog');
  const body = modal.querySelector('.team-picker-body');
  const isCross = teamPickerState.mode === 'cross';
  const isDual = isCross && teamPickerState.dualMode;
  dialog?.classList.toggle('dual-mode', isDual);
  body?.classList.toggle('dual', isDual);

  const toggleBtn = document.getElementById('teamPickerDualToggle');
  if (toggleBtn) {
    toggleBtn.classList.toggle('d-none', !isCross);
    toggleBtn.textContent = isDual ? '回到僅指定團隊' : '指定被評分團隊';
  }
  updateMirrorButtonState();
}

function applyTeamPickerMode(mode) {
  teamPickerState.mode = mode;
  const trigger = document.getElementById('teamPickerTrigger');
  const receiveTrigger = document.getElementById('receivePickerTrigger');
  const receiveClear = document.getElementById('receivePickerClear');

  const isSelectable = (mode === 'in' || mode === 'cross');
  teamPickerState.enabled = isSelectable;

  if (trigger) {
    trigger.setAttribute('aria-disabled', isSelectable ? 'false' : 'true');
    trigger.style.cursor = isSelectable ? 'pointer' : 'not-allowed';
  }

  if (receiveTrigger) {
    receiveTrigger.setAttribute('aria-disabled', mode === 'cross' ? 'false' : 'true');
    receiveTrigger.style.cursor = mode === 'cross' ? 'pointer' : 'not-allowed';
  }
  if (receiveClear) {
    receiveClear.style.visibility = mode === 'cross' ? 'visible' : 'hidden';
  }

  if (mode !== 'cross') {
    teamPickerState.dualMode = false;
  }

  if (!isSelectable) {
    setTeamSelections([], { role: 'assign', forceAllLabel: true });
    setTeamSelections([], { role: 'receive', forceAllLabel: true });
    teamPickerState.summaryMessage = '僅指定模式可挑選';
    teamPickerState.receiveSummaryMessage = '僅指定模式可挑選';
  } else {
    if (mode !== 'cross') {
      setTeamSelections([], { role: 'receive', forceAllLabel: true });
      teamPickerState.receiveSummaryMessage = '僅團隊間互評可設定';
    } else if (!teamPickerState.receiveIds.length) {
      teamPickerState.receiveSummaryMessage = '請選擇被評分團隊';
    }
    if (!teamPickerState.list.length) {
      const placeholder = '請先選擇屆別';
      teamPickerState.summaryMessage = placeholder;
      if (mode === 'cross') {
        teamPickerState.receiveSummaryMessage = placeholder;
      }
    } else if (!teamPickerState.selectedIds.length) {
      teamPickerState.summaryMessage = mode === 'cross' ? '請選擇團隊' : '[所有團隊]';
    }
  }

  syncTeamHiddenValue();
  updateReceiveFieldVisibility();
  updateTeamSummaryDisplay();
  updateModalLayout();
}

function updateTeamSummaryDisplay() {
  renderPickerDisplay({
    triggerId: 'teamPickerTrigger',
    summaryId: 'teamPickerSummary',
    tagsId: 'teamPickerTags',
    clearBtnId: 'teamPickerClear',
    enabled: teamPickerState.enabled,
    placeholder: teamPickerState.summaryMessage || '[所有團隊]',
    selections: teamPickerState.selectedIds,
    role: 'assign',
    disabledText: teamPickerState.summaryMessage || '僅指定模式可挑選'
  });
  renderPickerDisplay({
    triggerId: 'receivePickerTrigger',
    summaryId: 'receivePickerSummary',
    tagsId: 'receivePickerTags',
    clearBtnId: 'receivePickerClear',
    enabled: teamPickerState.mode === 'cross' && teamPickerState.enabled && teamPickerState.receiveIds.length > 0,
    placeholder: teamPickerState.receiveSummaryMessage || '[所有被評分團隊]',
    selections: teamPickerState.receiveIds,
    role: 'receive',
    disabledText: '僅團隊間互評可設定'
  });
}

function renderPickerDisplay(config) {
  const summary = document.getElementById(config.summaryId);
  const trigger = document.getElementById(config.triggerId);
  const clearBtn = document.getElementById(config.clearBtnId);
  const tagsEl = document.getElementById(config.tagsId);
  if (!summary || !trigger || !clearBtn || !tagsEl) return;

  trigger.classList.remove('disabled', 'has-value');
  clearBtn.style.visibility = 'hidden';
  tagsEl.innerHTML = '';

  if (!config.enabled) {
    const chip = document.createElement('span');
    chip.className = 'team-chip placeholder';
    chip.textContent = config.disabledText || config.placeholder;
    tagsEl.appendChild(chip);
    trigger.classList.add('disabled');
    summary.textContent = '';
    return;
  }

  const entries = (config.selections || [])
    .map(id => ({
      id,
      name: teamPickerState.list.find(t => String(t.team_ID) === id)?.team_project_name
    }))
    .filter(entry => entry.name);

  if (!entries.length) {
    const chip = document.createElement('span');
    chip.className = 'team-chip placeholder';
    chip.textContent = config.placeholder;
    tagsEl.appendChild(chip);
    summary.textContent = '';
    return;
  }

  entries.forEach(entry => {
    const chip = document.createElement('span');
    chip.className = 'team-chip';
    chip.textContent = entry.name;
    const remove = document.createElement('span');
    remove.className = 'remove';
    remove.textContent = '×';
    remove.addEventListener('click', (e) => {
      e.stopPropagation();
      const filtered = config.selections.filter(id => id !== entry.id);
      setTeamSelections(filtered, { role: config.role });
    });
    chip.appendChild(remove);
    tagsEl.appendChild(chip);
  });

  trigger.classList.add('has-value');
  clearBtn.style.visibility = 'visible';
  summary.textContent = '';
}

function openTeamPicker(targetRole = 'assign') {
  if (!teamPickerState.enabled) return;
  if (targetRole === 'receive' && teamPickerState.mode !== 'cross') return;
  const modal = document.getElementById('teamPickerModal');
  if (!modal) return;

  teamPickerState.modalSelections = [...teamPickerState.selectedIds];
  teamPickerState.receiveModalSelections = [...teamPickerState.receiveIds];
  const shouldDual = teamPickerState.mode === 'cross'
    && (targetRole === 'receive' || teamPickerState.dualMode);
  teamPickerState.dualMode = shouldDual;

  renderTeamModalLists();
  updateModalLayout();
  modal.classList.add('show');
}

function closeTeamPicker() {
  const modal = document.getElementById('teamPickerModal');
  if (modal) modal.classList.remove('show');
}

function renderTeamModalLists() {
  const placeholder = document.getElementById('teamModalPlaceholder');
  const hasTeams = Array.isArray(teamPickerState.list) && teamPickerState.list.length > 0;
  if (placeholder) {
    placeholder.style.display = hasTeams ? 'none' : 'flex';
  }

  ['assign', 'receive'].forEach(role => {
    renderModalSelectedDisplay(role);
    renderTeamModalList(role);
  });
  updateMirrorButtonState();
}

function getModalSelectionByRole(role) {
  return role === 'receive'
    ? (teamPickerState.receiveModalSelections || [])
    : (teamPickerState.modalSelections || []);
}

function renderModalSelectedDisplay(role) {
  const containerId = role === 'receive'
    ? 'teamModalReceiveSelected'
    : 'teamModalAssignSelected';
  const hintId = role === 'receive'
    ? 'teamModalReceiveHint'
    : 'teamModalAssignHint';
  const container = document.getElementById(containerId);
  const hintEl = document.getElementById(hintId);
  if (!container) return;

  const selections = getModalSelectionByRole(role);
  container.innerHTML = '';

  if (!selections.length) {
    const span = document.createElement('span');
    span.className = 'text-muted';
    span.textContent = '未選擇（儲存後等同全部團隊）';
    container.appendChild(span);
    if (hintEl) hintEl.textContent = '未選擇（儲存後等同全部團隊）';
    return;
  }

  selections.forEach((id) => {
    const team = teamPickerState.list.find(t => String(t.team_ID) === id);
    if (!team) return;
    const chip = document.createElement('span');
    chip.className = 'team-picker-chip';
    chip.textContent = team.team_project_name;
    const remove = document.createElement('span');
    remove.className = 'remove';
    remove.textContent = '×';
    remove.addEventListener('click', () => {
      toggleModalSelection(role, id);
    });
    chip.appendChild(remove);
    container.appendChild(chip);
  });
  if (!container.children.length) {
    const span = document.createElement('span');
    span.className = 'text-muted';
    span.textContent = '目前屬於不同屆別，無法顯示名稱';
    container.appendChild(span);
  }
  if (hintEl) {
    hintEl.textContent = `已選擇 ${selections.length} 個團隊`;
  }
}

function renderTeamModalList(role) {
  const listId = role === 'receive'
    ? 'teamModalReceiveList'
    : 'teamModalAssignList';
  const listEl = document.getElementById(listId);
  if (!listEl) return;

  const hasTeams = Array.isArray(teamPickerState.list) && teamPickerState.list.length > 0;
  listEl.innerHTML = '';
  if (!hasTeams) return;

  const selections = getModalSelectionByRole(role);
  teamPickerState.list.forEach(team => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'team-chip-option';
    btn.textContent = team.team_project_name;
    const idStr = String(team.team_ID);
    if (selections.includes(idStr)) {
      btn.classList.add('selected');
    }
    btn.addEventListener('click', () => toggleModalSelection(role, idStr));
    listEl.appendChild(btn);
  });
}

function toggleModalSelection(role, id) {
  const key = role === 'receive' ? 'receiveModalSelections' : 'modalSelections';
  const current = Array.isArray(teamPickerState[key]) ? teamPickerState[key] : [];
  if (current.includes(id)) {
    teamPickerState[key] = current.filter(item => item !== id);
  } else {
    teamPickerState[key] = [...current, id];
  }
  renderModalSelectedDisplay(role);
  renderTeamModalList(role);
  updateMirrorButtonState();
}

function setTeamSelections(values, options = {}) {
  const role = options.role === 'receive' ? 'receive' : 'assign';
  const summaryDefault = role === 'receive' ? '[所有被評分團隊]' : '[所有團隊]';
  const arr = Array.isArray(values)
    ? [...new Set(values.map(String).filter(Boolean))]
    : (!values || values === 'ALL') ? [] : [String(values)];

  const stateKey = role === 'receive' ? 'receiveIds' : 'selectedIds';
  const pendingKey = role === 'receive' ? 'pendingReceiveSelections' : 'pendingSelections';

  if (!arr.length) {
    teamPickerState[stateKey] = [];
    teamPickerState[pendingKey] = [];
    if (role === 'receive') {
      if (options.forceAllLabel) {
        teamPickerState.receiveSummaryMessage = summaryDefault;
      } else if (!options.keepMessage || !teamPickerState.receiveSummaryMessage) {
        teamPickerState.receiveSummaryMessage = summaryDefault;
      }
    } else {
      if (options.forceAllLabel) {
        teamPickerState.summaryMessage = summaryDefault;
      } else if (!options.keepMessage || !teamPickerState.summaryMessage) {
        teamPickerState.summaryMessage = summaryDefault;
      }
    }
    updateTeamSummaryDisplay();
    syncTeamHiddenValue();
    return;
  }

  const available = arr.filter(id => teamPickerState.list.some(t => String(t.team_ID) === id));
  const missing = arr.filter(id => !available.includes(id));

  teamPickerState[stateKey] = available;
  teamPickerState[pendingKey] = missing;
  if (!options.keepMessage) {
    if (role === 'receive') {
      teamPickerState.receiveSummaryMessage = '';
    } else {
      teamPickerState.summaryMessage = '';
    }
  }
  updateTeamSummaryDisplay();
  syncTeamHiddenValue();
  if (role === 'receive') {
    updateReceiveFieldVisibility();
  }
}

function syncTeamHiddenValue() {
  const hidden = document.getElementById('team_input');
  if (!hidden) return;
  const assign = teamPickerState.selectedIds || [];
  const receive = teamPickerState.receiveIds || [];
  if (teamPickerState.mode === 'cross') {
    if (!assign.length && !receive.length) {
      hidden.value = 'ALL';
      return;
    }
    hidden.value = JSON.stringify({
      assign,
      receive
    });
    return;
  }
  hidden.value = assign.length ? assign.join(',') : 'ALL';
}

function updateReceiveFieldVisibility() {
  const receiveField = document.getElementById('receiveTeamField');
  if (!receiveField) return;
  const hasReceive = Array.isArray(teamPickerState.receiveIds) && teamPickerState.receiveIds.length > 0;
  const shouldShow = teamPickerState.mode === 'cross' && hasReceive;
  receiveField.classList.toggle('d-none', !shouldShow);
  receiveField.hidden = !shouldShow;
  receiveField.style.display = shouldShow ? '' : 'none';
}

/* 載入資料表 */
function loadPeriodTable(page = 1) {
  const apiUrl = resolveCheckReviewPeriodsApiUrl();
  const sort = new URLSearchParams(window.location.search).get("sort") || "created";
  const params = new URLSearchParams({ sort });
  if (page > 1) {
    params.set('page', page);
  }
  return fetch(`${apiUrl}?${params.toString()}`)
      .then(r => {
          if (!r.ok) {
              throw new Error(`HTTP ${r.status}: ${r.statusText}`);
          }
          return r.text();
      })
      .then(html => {
          if (!html || html.trim() === '') {
              console.error('表格資料為空');
              const container = document.getElementById("periodTable");
              if (container) {
                  container.innerHTML = '<div class="alert alert-warning">無法載入表格資料（回應為空）</div>';
              }
              return;
          }
          const container = document.getElementById("periodTable");
          if (container) {
            // 先提取分頁資訊和頁碼容器
            let paginationData = null;
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            
            // 提取分頁資訊（從 script 標籤中）
            const scripts = tempDiv.querySelectorAll('script');
            for (const script of scripts) {
              const content = script.textContent || script.innerHTML;
              const match = content.match(/window\.periodPaginationData\s*=\s*({[\s\S]*?});/);
              if (match) {
                try {
                  paginationData = JSON.parse(match[1]);
                  break;
                } catch (e) {
                  console.warn('無法解析分頁資訊:', e);
                }
              }
            }
            
            // 提取後端返回的頁碼容器
            const backendPager = tempDiv.querySelector('#periodPagerBar');
            
            // 設置表格內容（不包含頁碼容器）
            if (backendPager) {
              backendPager.remove();
            }
            container.innerHTML = tempDiv.innerHTML;
            
            // 將後端返回的頁碼容器移到表格容器後面（作為兄弟元素，在 card 內部）
            if (backendPager) {
              // 移除前端的頁碼容器（如果存在且不同）
              const frontendPager = document.getElementById('periodPagerBar');
              if (frontendPager && frontendPager !== backendPager) {
                frontendPager.remove();
              }
              // 將後端頁碼容器插入到表格容器後面（作為兄弟元素）
              container.parentNode.insertBefore(backendPager, container.nextSibling);
            }
            
            // 手動執行所有插入的 <script> 標籤
            const insertedScripts = container.querySelectorAll('script');
            insertedScripts.forEach(oldScript => {
              const newScript = document.createElement('script');
              Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
              });
              newScript.textContent = oldScript.textContent;
              oldScript.parentNode.replaceChild(newScript, oldScript);
            });
            
            // 處理頁碼
            // 優先使用從 HTML 中提取的資訊，如果沒有則使用 window.periodPaginationData
            // 使用 setTimeout 確保 DOM 已完全更新，並且 script 已執行
            setTimeout(() => {
              const finalPaginationData = paginationData || window.periodPaginationData;
              const pagerBar = document.getElementById('periodPagerBar');
              
              if (finalPaginationData && typeof finalPaginationData.pages === 'number') {
                // 即使只有一頁也顯示頁碼
                buildPeriodPager(finalPaginationData.page, finalPaginationData.pages);
              } else {
                // 如果沒有分頁數據，至少顯示第一頁
                if (pagerBar) {
                  buildPeriodPager(1, 1);
                }
              }
            }, 200);
          }
          updateReceiveFieldVisibility();
      })
      .catch(err => {
          console.error('載入資料表失敗:', err);
          const tableEl = document.getElementById("periodTable");
          if (tableEl) tableEl.innerHTML = '<div class="alert alert-danger">資料載入失敗</div>';
      });
}

/* 建立頁碼 */
function buildPeriodPager(page, pages) {
  const pager = document.getElementById('periodPagerBar');
  if (!pager) {
    console.error('找不到頁碼容器 periodPagerBar');
    return;
  }

  // 確保頁碼始終顯示（使用 block 而不是 flex，因為 CSS 使用 text-align: center）
  pager.style.display = 'block';
  
  // 如果沒有頁數或只有一頁，只顯示當前頁
  if (!pages || pages <= 1) {
    pager.innerHTML = '<span class="active">1</span>';
    return;
  }
  let html = '';

  if (page > 1) {
    html += `<a href="#" data-page="${page - 1}">&laquo;</a>`;
  } else {
    html += '<span class="disabled">&laquo;</span>';
  }

  for (let i = 1; i <= pages; i++) {
    if (i === page) {
      html += `<span class="active">${i}</span>`;
    } else {
      html += `<a href="#" data-page="${i}">${i}</a>`;
    }
  }

  if (page < pages) {
    html += `<a href="#" data-page="${page + 1}">&raquo;</a>`;
  } else {
    html += '<span class="disabled">&raquo;</span>';
  }

  pager.innerHTML = html;

  // 綁定點擊事件
  pager.querySelectorAll('a[data-page]').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      const p = parseInt(a.dataset.page, 10);
      if (!isNaN(p)) {
        loadPeriodTable(p);
      }
    });
  });
}

/* 編輯 */
function editRow(row) {
  const currentMode = row.mode || row.pe_mode || '';
  document.getElementById('form_action').value = 'update';
  document.getElementById('submitBtn').innerText = '更新';

  document.getElementById('period_ID').value = row.period_ID || '';
  const startInput = document.getElementById('period_start_d');
  const endInput = document.getElementById('period_end_d');
  const titleInput = document.getElementById('period_title');
  if (startInput) {
    startInput.value = (row.period_start_d || '').replace(' ', 'T');
  }
  if (endInput) {
    endInput.value = (row.period_end_d || '').replace(' ', 'T');
  }
  if (titleInput) {
    titleInput.value = row.period_title || '';
  }
  const cohortValuesInput = document.getElementById('cohort_values');
  if (cohortValuesInput) {
    cohortValuesInput.value = row.cohort_values || '';
  }
  const selectedCohort = row.cohort_ID
    ? [String(row.cohort_ID)]
    : (row.cohort_values ? row.cohort_values.split(',').filter(Boolean) : []);
  setSelectedCohorts(selectedCohort, false);
  const selectedClass = row.pe_class_ID ? [String(row.pe_class_ID)] : [];
  setClassSelections(selectedClass, false);
  const selectionPayload = parseTeamAssignmentPayload(row.pe_target_ID);
  const assignList = selectionPayload.assign || [];
  const receiveList = selectionPayload.receive || [];
  setTeamSelections(assignList, {
    role: 'assign',
    keepMessage: !!assignList.length,
    forceAllLabel: !assignList.length
  });
  setTeamSelections(receiveList, {
    role: 'receive',
    keepMessage: !!receiveList.length,
    forceAllLabel: !receiveList.length
  });
  teamPickerState.dualMode = receiveList.length > 0;
  // 載入對應屆別的團隊，預選現有值
  loadTeamList(selectedCohort, selectedClass, selectionPayload);

  if (currentMode) {
    const hiddenModeInput = document.getElementById('mode_value');
    if (hiddenModeInput) hiddenModeInput.value = currentMode;
    applyTeamPickerMode(currentMode);
    const labelEl = document.getElementById('modeLabel');
    const targetBtn = document.querySelector(`.mode-option[data-mode="${currentMode}"]`);
    if (labelEl && targetBtn) {
      labelEl.textContent = targetBtn.textContent.trim();
    }
  }
  const statusInput = document.getElementById('pe_status');
  const statusValue = Number(row.pe_status ?? row.status_ID ?? 0);
  if (statusInput) statusInput.value = statusValue === 1 ? '1' : '0';
  const cancelBtn = document.getElementById('cancelEditBtn');
  if (cancelBtn) cancelBtn.classList.remove('d-none');

  window.scrollTo({ top: 0, behavior: "smooth" });
}

/* 清空表單 */
function resetForm() {
  document.getElementById('form_action').value = 'create';
  document.getElementById('submitBtn').innerText = '新增';

  document.getElementById('period_ID').value = '';
  document.getElementById('period_start_d').value = '';
  document.getElementById('period_end_d').value = '';
  document.getElementById('period_title').value = '';
  setClassSelections([], false);
  setSelectedCohorts([], true);
  teamPickerState.summaryMessage = '請先選擇屆別';
  teamPickerState.receiveSummaryMessage = '僅團隊間互評可設定';
  teamPickerState.dualMode = false;
  setTeamSelections([], { role: 'assign', keepMessage: true });
  setTeamSelections([], { role: 'receive', keepMessage: true });
  const statusInput = document.getElementById('pe_status');
  if (statusInput) statusInput.value = '1';
  const defaultMode = 'in';
  const hiddenModeInput = document.getElementById('mode_value');
  if (hiddenModeInput) hiddenModeInput.value = defaultMode;
  applyTeamPickerMode(defaultMode);
  const labelEl = document.getElementById('modeLabel');
  const hintEl = document.getElementById('modeHint');
  const targetBtn = document.querySelector(`.mode-option[data-mode="${defaultMode}"]`);
  if (labelEl && targetBtn) labelEl.textContent = targetBtn.textContent.trim();
  if (hintEl && targetBtn) hintEl.textContent = targetBtn.dataset.hint || ' ';
  const cancelBtn = document.getElementById('cancelEditBtn');
  if (cancelBtn) cancelBtn.classList.add('d-none');
}

window.editRow = editRow;
window.resetForm = resetForm;

function setupPeriodTableDelegation() {
  const container = document.getElementById('periodTable');
  if (!container || container.dataset.tableDelegation === 'true') return;
  container.dataset.tableDelegation = 'true';
  container.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.classList || !form.classList.contains('period-delete-form')) {
      return;
    }
    event.preventDefault();
    handlePeriodDelete(form);
  });
}

async function handlePeriodDelete(form) {
  if (!form) return;
  
  // 使用 SweetAlert2 彈跳視窗確認刪除
  let confirmed = false;
  if (window.Swal) {
    const result = await Swal.fire({
      title: '確認刪除',
      text: '確定要刪除此評分時段嗎？此操作無法復原。',
      icon: 'warning',
      showCancelButton: true,
      reverseButtons: true,
      confirmButtonText: '確定刪除',
      cancelButtonText: '取消',
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6'
    });
    confirmed = result.isConfirmed;
  } else {
    // 如果沒有 SweetAlert2，回退到原生 confirm
    confirmed = typeof window.confirm === 'function'
      ? window.confirm('確定刪除？')
      : true;
  }
  
  if (!confirmed) return;
  
  const actionUrl = form.getAttribute('action') || resolveCheckReviewPeriodsApiUrl();
  const formData = new FormData(form);
  formData.set('action', 'delete');
  try {
    const res = await fetch(actionUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    let result = null;
    try {
      result = await res.json();
    } catch (err) {
      result = null;
    }
    if (!res.ok || (result && result.success === false)) {
      throw new Error(result?.msg || `刪除失敗 (HTTP ${res.status})`);
    }
    
    // 顯示刪除成功訊息
    if (window.Swal) {
      await Swal.fire({
        icon: 'success',
        title: '刪除成功',
        text: '評分時段已成功刪除',
        timer: 2000,
        showConfirmButton: false
      });
    }
    
    await loadPeriodTable();
  } catch (err) {
    console.error('刪除失敗:', err);
    if (window.Swal) {
      Swal.fire({
        icon: 'error',
        title: '刪除失敗',
        text: err.message || '請稍後再試',
        reverseButtons: true,
        confirmButtonText: '確定',
        confirmButtonColor: '#3085d6'
      });
    } else {
      alert(err.message || '刪除失敗');
    }
  }
}

})();
