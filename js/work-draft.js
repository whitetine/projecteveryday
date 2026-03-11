// 動態解析 API URL（支援動態載入）
function resolveWorkDraftApiUrl() {
  const path = window.location.pathname || '';
  if (path.includes('/pages/')) {
    return 'work_draft_data.php';
  }
  return 'pages/work_draft_data.php';
}
//註解
window.initWorkDraft = function () {
  const table = document.querySelector('#work-table-body');
  if (!table) {
    window._workDraftInitialized = false;
    return false;
  }
  
  // 檢查元素是否在當前 DOM 中（頁面切換時可能元素被移除又重新加入）
  const isInDOM = document.body.contains(table);
  if (!isInDOM) {
    window._workDraftInitialized = false;
    return false;
  }
  
  // 如果已經初始化過，但元素仍然存在，先重置再重新初始化（處理頁面切換的情況）
  if (window._workDraftInitialized) {
    window._workDraftInitialized = false;
  }
  
  window._workDraftInitialized = true;

  const tbody = document.querySelector('#work-table-body');
  const pager = document.querySelector('#pager-bar');
  const filterForm = document.getElementById('filter-form');
  const isTeacher = filterForm?.dataset.isTeacher === '1';
  const teamSelect = isTeacher ? filterForm?.querySelector('select[name="team"]') : null;
  const whoSelect = filterForm?.querySelector('select[name="who"]');
  const fromInput = filterForm?.querySelector('input[name="from"]');
  const toInput = filterForm?.querySelector('input[name="to"]');

  let showAuthor = false;
  let currentPage = 1;
  let totalPages = 1;
  let currentWorkId = null;
  let teacherTeamsCache = [];
  let currentView = 'timeline'; // 'timeline' 或 'table'
  let currentRows = []; // 儲存當前數據以便切換視圖

  // HTML escape helper
  function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderTableHead(showAuthorFlag) {
    let html = '';
    if (showAuthorFlag) {
      // 有提交者欄位：時間12% + 提交者12% + 標題20% + 內容預覽30% + 狀態13% + 留言13% = 100%
      html += '<th style="width:12%">時間</th>';
      html += '<th style="width:12%">提交者</th>';
      html += '<th style="width:20%">標題</th>';
      html += '<th style="width:30%">內容預覽</th>';
      html += '<th style="width:13%">狀態</th>';
      html += '<th style="width:13%">留言</th>';
    } else {
      // 沒有提交者欄位：時間15% + 標題25% + 內容預覽35% + 狀態12.5% + 留言12.5% = 100%
      html += '<th style="width:15%">時間</th>';
      html += '<th style="width:25%">標題</th>';
      html += '<th style="width:35%">內容預覽</th>';
      html += '<th style="width:12.5%">狀態</th>';
      html += '<th style="width:12.5%">留言</th>';
    }

    const theadRow = document.getElementById('work-thead-row');
    if (theadRow) {
      theadRow.innerHTML = html;
    }
  }

  function renderRows(rows) {
    currentRows = rows || [];
    
    if (currentView === 'timeline') {
      renderTimeline(rows);
    } else {
      renderTable(rows);
    }
  }

  function renderTable(rows) {
    const hasAuthor = showAuthor;
    const colspan = hasAuthor ? 6 : 5;

    if (!Array.isArray(rows) || rows.length === 0) {
      tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted py-4">查無資料</td></tr>`;
      return;
    }

    const html = rows.map(r => {
      const isDraft = Number(r.work_status) === 1;
      const rowClass = isDraft ? 'table-warning' : '';
      const statusHtml = isDraft
        ? '<span class="badge bg-warning text-dark">暫存</span>'
        : '<span class="badge bg-success">已送出</span>';

      const dateLabel = escapeHtml(r.work_update_dt || '');
      const title = escapeHtml(r.work_title || '');
      const content = escapeHtml(r.work_content || '');
      const authorName = escapeHtml(r.author_name || '');

      const canComment = Number(r.work_status) === 3;
      const commentHtml = canComment
        ? `<button type="button" class="btn btn-sm btn-primary comment-btn" data-toggle="modal" data-target="#commentModal" data-id="${r.work_ID}">留言</button>`
        : '<span class="text-muted">－</span>';

      return `
        <tr class="${rowClass}">
          <td class="time-cell">${dateLabel}</td>
          ${hasAuthor ? `<td>${authorName}</td>` : ''}
          <td><div class="title-preview">${title}</div></td>
          <td><div class="content-preview">${content}</div></td>
          <td>${statusHtml}</td>
          <td>${commentHtml}</td>
        </tr>
      `;
    }).join('');

    tbody.innerHTML = html;
    
    // 確保按鈕不會被禁用，並重新初始化 Bootstrap Modal
    tbody.querySelectorAll('.comment-btn').forEach(btn => {
      btn.disabled = false;
      btn.removeAttribute('disabled');
      btn.style.pointerEvents = 'auto';
      btn.style.cursor = 'pointer';
      
      // 移除舊的事件監聽器，添加新的事件監聽器
      const newBtn = btn.cloneNode(true);
      btn.parentNode.replaceChild(newBtn, btn);
      
      // 為新按鈕添加點擊事件
      newBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const modalId = this.getAttribute('data-target');
        const workId = this.getAttribute('data-id');
        if (modalId && workId) {
          currentWorkId = workId;
          // 將 work_id 存儲到 Modal 的 data 屬性中，作為備份
          $(modalId).data('work-id', workId);
          // 使用 jQuery 觸發 Modal（Bootstrap 4）
          $(modalId).modal('show');
        }
      });
    });
    
    // 確保其他按鈕也不會被禁用
    tbody.querySelectorAll('.btn:not(.comment-btn)').forEach(btn => {
      btn.disabled = false;
      btn.removeAttribute('disabled');
    });
  }

  function renderTimeline(rows) {
    const timelineItems = document.getElementById('work-timeline-items');
    if (!timelineItems) return;

    if (!Array.isArray(rows) || rows.length === 0) {
      timelineItems.innerHTML = '<div class="text-center text-muted py-4">查無資料</div>';
      return;
    }

    const hasAuthor = showAuthor;
    const html = rows.map((r, index) => {
      const isDraft = Number(r.work_status) === 1;
      const statusHtml = isDraft
        ? '<span class="badge bg-warning text-dark timeline-item-status">暫存</span>'
        : '<span class="badge bg-success timeline-item-status">已送出</span>';

      const dateLabel = escapeHtml(r.work_update_dt || '');
      const title = escapeHtml(r.work_title || '');
      const content = escapeHtml(r.work_content || '');
      const authorName = escapeHtml(r.author_name || '');

      const canComment = Number(r.work_status) === 3;
      const commentHtml = canComment
        ? `<button type="button" class="btn btn-sm btn-primary comment-btn" data-toggle="modal" data-target="#commentModal" data-id="${r.work_ID}">留言</button>`
        : '';

      // 狀態標籤
      const statusTag = isDraft ? '<span class="timeline-tag">暫存</span>' : '<span class="timeline-tag">已送出</span>';

      return `
        <article class="timeline-item" data-id="${r.work_ID}">
          <div class="timeline-marker">
            <div class="timeline-dot"></div>
          </div>
          <div class="timeline-content">
            <div class="timeline-status-top">${statusHtml}</div>
            <div class="timeline-label">標題</div>
            <h3 class="timeline-title">${title}</h3>
            <div class="timeline-label">內容</div>
            <div class="timeline-text">${content}</div>
            <div class="timeline-footer">
              <time class="timeline-date">${dateLabel}</time>
              <div class="timeline-actions">${commentHtml}</div>
            </div>
          </div>
        </article>
      `;
    }).join('');

    timelineItems.innerHTML = html;
    
    // 確保按鈕不會被禁用，並重新初始化 Bootstrap Modal
    timelineItems.querySelectorAll('.comment-btn').forEach(btn => {
      btn.disabled = false;
      btn.removeAttribute('disabled');
      btn.style.pointerEvents = 'auto';
      btn.style.cursor = 'pointer';
      
      // 移除舊的事件監聽器，添加新的事件監聽器
      const newBtn = btn.cloneNode(true);
      btn.parentNode.replaceChild(newBtn, btn);
      
      // 為新按鈕添加點擊事件
      newBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const modalId = this.getAttribute('data-target');
        const workId = this.getAttribute('data-id');
        if (modalId && workId) {
          currentWorkId = workId;
          // 將 work_id 存儲到 Modal 的 data 屬性中，作為備份
          $(modalId).data('work-id', workId);
          // 使用 jQuery 觸發 Modal（Bootstrap 4）
          $(modalId).modal('show');
        }
      });
    });
    
    // 確保其他按鈕也不會被禁用
    timelineItems.querySelectorAll('.btn:not(.comment-btn)').forEach(btn => {
      btn.disabled = false;
      btn.removeAttribute('disabled');
    });
  }

  function switchView(view) {
    currentView = view;
    const timelineView = document.getElementById('view-timeline');
    const tableView = document.getElementById('view-table');
    const timelineBtn = document.getElementById('view-toggle-timeline');
    const tableBtn = document.getElementById('view-toggle-table');

    if (view === 'timeline') {
      timelineView?.classList.remove('d-none');
      tableView?.classList.add('d-none');
      timelineBtn?.classList.add('active');
      tableBtn?.classList.remove('active');
    } else {
      timelineView?.classList.add('d-none');
      tableView?.classList.remove('d-none');
      timelineBtn?.classList.remove('active');
      tableBtn?.classList.add('active');
    }

    // 重新渲染當前數據
    renderRows(currentRows);
  }

  function buildPager(page, pages) {
    if (!pager) return;

    if (!pages || pages <= 1) {
      pager.innerHTML = '<span class="disabled">1</span>';
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

    pager.querySelectorAll('a[data-page]').forEach(a => {
      a.addEventListener('click', e => {
        e.preventDefault();
        const p = parseInt(a.dataset.page, 10);
        if (!isNaN(p)) {
          loadList(p);
        }
      });
    });
  }

  function updateWhoOptions(meId, teamMembers, currentWho) {
    if (isTeacher) return;
    if (!whoSelect) return;
    // 每次依回傳資料重建，保持簡單
    const options = [];

    options.push({
      value: 'me',
      label: '我的日誌（自己）'
    });

    const hasTeam = Array.isArray(teamMembers) && teamMembers.length > 0;
    if (hasTeam) {
      options.push({
        value: 'team',
        label: '本團隊 - 學生全部'
      });

      teamMembers.forEach(m => {
        if (m.id === meId) return; // 跟「自己」重複
        options.push({
          value: m.id,
          label: m.name || m.id
        });
      });
    }

    whoSelect.innerHTML = options.map(opt => {
      const selected = opt.value === currentWho ? 'selected' : '';
      return `<option value="${escapeHtml(opt.value)}" ${selected}>${escapeHtml(opt.label)}</option>`;
    }).join('');
  }

  function populateTeacherStudentSelect(teamId, selectedStudent = 'team', shouldLoadData = false) {
    if (!isTeacher || !whoSelect) return;
    if (!teamId) {
      whoSelect.innerHTML = '<option value="">請先選擇團隊</option>';
      whoSelect.disabled = true;
      return;
    }
    const team = teacherTeamsCache.find(t => String(t.team_ID) === String(teamId));
    if (!team) {
      whoSelect.innerHTML = '<option value="">找不到學生</option>';
      whoSelect.disabled = true;
      return;
    }
    const students = team.students || [];
    let html = `<option value="team">全部學生</option>`;
    students.forEach(stu => {
      html += `<option value="${escapeHtml(stu.id)}">${escapeHtml(stu.name || stu.id)}</option>`;
    });
    whoSelect.innerHTML = html;
    whoSelect.disabled = false;
    const validValues = students.map(stu => String(stu.id)).concat(['team']);
    if (validValues.includes(String(selectedStudent))) {
      whoSelect.value = selectedStudent;
    } else {
      whoSelect.value = 'team';
    }
    
    // 如果需要載入數據（例如團隊改變時），觸發 change 事件
    if (shouldLoadData) {
      whoSelect.dispatchEvent(new Event('change'));
    }
  }

  function updateTeacherOptions(teams = [], selectedTeam = '', selectedStudent = 'team') {
    if (!isTeacher || !teamSelect) return;
    teacherTeamsCache = Array.isArray(teams) ? teams : [];
    if (!teacherTeamsCache.length) {
      teamSelect.innerHTML = '<option value="">尚未指導任何團隊</option>';
      teamSelect.disabled = true;
      populateTeacherStudentSelect('', '');
      return;
    }
    teamSelect.disabled = false;
    let html = '';
    teacherTeamsCache.forEach(t => {
      const label = escapeHtml(t.team_name || `Team ${t.team_ID}`);
      html += `<option value="${escapeHtml(String(t.team_ID))}">${label}</option>`;
    });
    teamSelect.innerHTML = html;
    if (selectedTeam && teacherTeamsCache.some(t => String(t.team_ID) === String(selectedTeam))) {
      teamSelect.value = selectedTeam;
    } else {
      selectedTeam = teamSelect.value || (teacherTeamsCache[0] && teacherTeamsCache[0].team_ID);
      teamSelect.value = selectedTeam;
    }
    populateTeacherStudentSelect(selectedTeam, selectedStudent);
  }

  async function loadList(page = 1) {
    try {
      const params = new URLSearchParams({ action: 'list', page });
      if (isTeacher) {
        if (teamSelect?.value) params.set('team', teamSelect.value);
        if (whoSelect?.value) params.set('who', whoSelect.value);
      } else if (whoSelect?.value) {
        params.set('who', whoSelect.value);
      }
      if (fromInput?.value) params.set('from', fromInput.value);
      if (toInput?.value) params.set('to', toInput.value);

      const apiUrl = `${resolveWorkDraftApiUrl()}?${params.toString()}`;
      console.log('work-draft fetch:', apiUrl);

      const res = await fetch(apiUrl, { credentials: 'same-origin' });
      if (!res.ok) {
        console.error('API Error:', res.status);
        throw new Error(`HTTP ${res.status}`);
      }

      const j = await res.json();
      if (!j.ok) {
        console.error('API Response Error:', j);
        const errorMsg = j.msg || j.error || '載入失敗';
        throw new Error(errorMsg);
      }

      // 更新篩選值（後端會把預設/修正後的值回傳）
      if (j.filter) {
        if (j.filter.from !== undefined) {
          fromInput.value = j.filter.from || '';
        }
        if (j.filter.to !== undefined) {
          toInput.value = j.filter.to || '';
        }
      }

      // 更新 who 選項
      if (isTeacher) {
        const selectedTeam = j.teacherSelectedTeam || '';
        const selectedStudent = (j.filter && j.filter.who) || 'team';
        updateTeacherOptions(j.teacherTeams || [], selectedTeam, selectedStudent);
      } else if (whoSelect && j.me && j.teamMembers) {
        const currentWho = (j.filter && j.filter.who) || 'me';
        updateWhoOptions(j.me, j.teamMembers, currentWho);
      }

      // 如果後端因為參數修正過 who，也同步更新
      if (whoSelect && j.filter && j.filter.who && whoSelect.value !== j.filter.who) {
        whoSelect.value = j.filter.who;
        // 更新視圖切換器顯示狀態（在設置值之後立即更新）
        updateViewToggleVisibility();
      }

      showAuthor = !!j.showAuthor;
      renderTableHead(showAuthor);
      renderRows(j.rows || []);
      currentPage = j.page || 1;
      totalPages = j.pages || 1;
      buildPager(currentPage, totalPages);
      
      // 更新視圖切換器顯示狀態（確保在數據載入後也更新）
      updateViewToggleVisibility();
      
      // 確保視圖正確顯示
      switchView(currentView);
    } catch (err) {
      console.error('work-draft loadList error:', err);
      const colspan = showAuthor ? 6 : 5;
      const errorMsg = err.message || '資料載入失敗';
      tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-danger text-center">${escapeHtml(errorMsg)}</td></tr>`;
      pager.innerHTML = '<span class="disabled">1</span>';
      
      // 如果是时间轴视图，也显示错误
      const timelineItems = document.getElementById('work-timeline-items');
      if (timelineItems) {
        timelineItems.innerHTML = `<div class="text-center text-danger py-4">${escapeHtml(errorMsg)}</div>`;
      }
    }
  }

  if (isTeacher && teamSelect) {
    teamSelect.addEventListener('change', () => {
      // 團隊改變時更新學生選項，並觸發數據載入
      populateTeacherStudentSelect(teamSelect.value, 'team', true);
      // 團隊改變時，預設選擇"全部學生"，需要更新視圖切換器狀態
      setTimeout(() => {
        updateViewToggleVisibility();
      }, 100);
    });
  }

  // 查看對象改變時自動重新載入數據並控制視圖切換器
  if (whoSelect) {
    whoSelect.addEventListener('change', () => {
      updateViewToggleVisibility();
      loadList(1);
    });
  }

  // 更新視圖切換器的顯示/隱藏
  function updateViewToggleVisibility() {
    const viewToggleContainer = document.getElementById('view-toggle-container');
    if (!viewToggleContainer || !whoSelect) return;
    
    const selectedValue = whoSelect.value;
    // 指導老師或學生：選擇"team"（全部學生/本團隊全部）時隱藏視圖切換器並強制使用表格視圖
    // 選擇單個學生或個人時顯示視圖切換器，可以切換表格或時間線
    if (selectedValue === 'team') {
      // 選擇全部學生/本團隊全部時，隱藏視圖切換器並強制使用表格視圖
      viewToggleContainer.style.display = 'none';
      if (currentView !== 'table') {
        switchView('table');
      }
    } else {
      // 選擇單個學生或個人時，顯示視圖切換器
      viewToggleContainer.style.display = 'flex';
    }
  }

  // 篩選表單送出
  filterForm?.addEventListener('submit', e => {
    e.preventDefault();
    loadList(1);
  });

  // 視圖切換按鈕
  document.getElementById('view-toggle-timeline')?.addEventListener('click', () => {
    // 只有不是選擇"team"（全部學生/本團隊全部）時才允許切換到時間線
    const selectedValue = whoSelect?.value;
    if (selectedValue && selectedValue !== 'team') {
      switchView('timeline');
    }
  });

  document.getElementById('view-toggle-table')?.addEventListener('click', () => {
    switchView('table');
  });

  // 初始化時檢查視圖切換器顯示狀態
  updateViewToggleVisibility();

  // 確保 Modal 在 body 的直接子元素（避免被其他元素遮擋）
  const viewModal = document.getElementById('viewModal');
  const commentModal = document.getElementById('commentModal');
  
  if (viewModal && viewModal.parentElement !== document.body) {
    document.body.appendChild(viewModal);
  }
  if (commentModal && commentModal.parentElement !== document.body) {
    document.body.appendChild(commentModal);
  }

  // 查看 Modal：顯示內容
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', e => {
      const button = e.relatedTarget;
      if (!button) return;
      const title = button.getAttribute('data-title') || '';
      const date = button.getAttribute('data-date') || '';
      const content = button.getAttribute('data-content') || '';

      viewModal.querySelector('#vm-title').textContent = title;
      viewModal.querySelector('#vm-date').textContent = date;
      viewModal.querySelector('#vm-content').textContent = content;
    });
  }

  // 留言 Modal：載入 & 送出
  if (commentModal) {
    // 使用標誌防止重複綁定事件監聽器
    if (commentModal.dataset.workDraftCommentInitialized === 'true') {
      return;
    }
    commentModal.dataset.workDraftCommentInitialized = 'true';
    
    const listBox = commentModal.querySelector('#cmn-list');
    const textArea = commentModal.querySelector('#cmn-text');
    const submitBtn = commentModal.querySelector('#cmn-submit');
    
    if (!submitBtn || !textArea) {
      console.error('Submit button or textarea not found in comment modal');
      return;
    }

    // 移除舊的事件監聽器（防止重複綁定）
    $(commentModal).off('show.bs.modal');

    $(commentModal).on('show.bs.modal', async function(e) {
      const button = $(e.relatedTarget);
      
      // 先清除舊的留言內容，避免顯示上一個日誌的留言
      listBox.textContent = '載入中...';
      textArea.value = '';
      
      // 優先從按鈕獲取 work_id
      let newWorkId = null;
      if (button.length) {
        newWorkId = button.attr('data-id');
      }
      
      // 如果按鈕沒有 work_id，嘗試從 Modal 的 data 屬性獲取
      if (!newWorkId) {
        newWorkId = $(commentModal).data('work-id');
      }
      
      // 如果還是沒有，返回錯誤
      if (!newWorkId) {
        console.error('無法取得工作記錄ID');
        listBox.textContent = '無法取得工作記錄ID';
        alert('無法取得工作記錄ID，請重新開啟留言視窗');
        $(commentModal).modal('hide');
        return;
      }
      
      // 更新 currentWorkId
      currentWorkId = newWorkId;

      try {
        const fd = new FormData();
        fd.append('action', 'get_comments');
        fd.append('work_id', currentWorkId);

        const res = await fetch(resolveWorkDraftApiUrl(), {
          method: 'POST',
          credentials: 'same-origin',
          body: fd
        });

        const j = await res.json();
        if (!j.ok) {
          listBox.textContent = '讀取失敗';
          return;
        }

        if (!j.comments || j.comments.length === 0) {
          listBox.textContent = '尚無留言';
          return;
        }

        listBox.innerHTML = j.comments.map(c => {
          const name = escapeHtml(c.name || c.uid || '');
          const text = escapeHtml(c.text || '');
          const time = escapeHtml(c.at || '');
          return `
            <div class="comment-item">
              <div class="comment-header">
                <span class="comment-author"><b>${name}</b></span>
                ${time ? `<span class="comment-time">${time}</span>` : ''}
              </div>
              <div class="comment-text">${text}</div>
            </div>
          `;
        }).join('');
        
        // 滚动到底部显示最新留言
        listBox.scrollTop = listBox.scrollHeight;
      } catch (err) {
        console.error(err);
        listBox.textContent = '讀取失敗';
      }
    });
    
    // Modal 關閉時清除留言內容和 work_id，確保下次打開時不會顯示舊數據
    $(commentModal).on('hidden.bs.modal', function() {
      listBox.textContent = '';
      textArea.value = '';
      currentWorkId = null;
      $(commentModal).removeData('work-id');
    });

    // 使用 once 選項確保事件只觸發一次，或使用標誌防止重複執行
    let isSubmitting = false;
    
    submitBtn.addEventListener('click', async (e) => {
      // 防止重複提交
      if (isSubmitting) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      
      // 重新獲取 textArea，因為可能已經更新
      const currentTextArea = commentModal.querySelector('#cmn-text');
      if (!currentTextArea) {
        console.error('Textarea not found');
        return;
      }
      
      const t = currentTextArea.value.trim();
      if (!t) {
        alert('請輸入留言內容');
        return;
      }
      
      // 如果沒有 currentWorkId，嘗試從 Modal 的 data 屬性獲取
      if (!currentWorkId) {
        const modalWorkId = $(commentModal).data('work-id');
        if (modalWorkId) {
          currentWorkId = modalWorkId;
        }
      }
      
      // 如果還是沒有，返回錯誤
      if (!currentWorkId) {
        console.error('無法取得工作記錄ID - currentWorkId:', currentWorkId);
        alert('無法取得工作記錄ID，請重新開啟留言視窗');
        isSubmitting = false;
        return;
      }

      // 設置標誌防止重複提交
      isSubmitting = true;
      
      // 禁用按鈕防止重複提交
      submitBtn.disabled = true;
      submitBtn.textContent = '送出中...';

      try {
        const fd = new FormData();
        fd.append('action', 'add_comment');
        fd.append('work_id', currentWorkId);
        fd.append('text', t);

        const res = await fetch(resolveWorkDraftApiUrl(), {
          method: 'POST',
          credentials: 'same-origin',
          body: fd
        });

        const j = await res.json();
        if (!j.ok) {
          alert('留言送出失敗，請稍後再試');
          isSubmitting = false;
          submitBtn.disabled = false;
          submitBtn.textContent = '送出留言';
          return;
        }

        if (!j.comments || j.comments.length === 0) {
          listBox.textContent = '尚無留言';
          currentTextArea.value = '';
          isSubmitting = false;
          submitBtn.disabled = false;
          submitBtn.textContent = '送出留言';
          return;
        }

        listBox.innerHTML = j.comments.map(c => {
          const name = escapeHtml(c.name || c.uid || '');
          const text = escapeHtml(c.text || '');
          const time = escapeHtml(c.at || '');
          return `
            <div class="comment-item">
              <div class="comment-header">
                <span class="comment-author"><b>${name}</b></span>
                ${time ? `<span class="comment-time">${time}</span>` : ''}
              </div>
              <div class="comment-text">${text}</div>
            </div>
          `;
        }).join('');
        currentTextArea.value = '';
        
        // 滚动到底部显示最新留言
        listBox.scrollTop = listBox.scrollHeight;
        
        // 恢復按鈕狀態
        isSubmitting = false;
        submitBtn.disabled = false;
        submitBtn.textContent = '送出留言';
      } catch (err) {
        console.error(err);
        alert('留言送出失敗，請稍後再試');
        isSubmitting = false;
        submitBtn.disabled = false;
        submitBtn.textContent = '送出留言';
      }
    });
    
    // 按 Enter + Ctrl 或 Enter + Shift 送出留言
    const textAreaEl = commentModal.querySelector('#cmn-text');
    if (textAreaEl && !textAreaEl.dataset.workDraftKeydownListener) {
      textAreaEl.dataset.workDraftKeydownListener = 'true';
      textAreaEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey || e.shiftKey)) {
          e.preventDefault();
          submitBtn.click();
        }
      });
    }
  }

  // 首次載入
  loadList(1);

  return true;
};

// 根據 DOM 狀態決定如何初始化
function tryInitWorkDraft() {
  const filterForm = document.getElementById('filter-form');
  if (filterForm) {
    // 如果元素存在但初始化標記已設定，先重置（處理頁面切換的情況）
    if (window._workDraftInitialized) {
      window._workDraftInitialized = false;
    }
    initWorkDraft();
    return true;
  } else {
    // 如果元素不存在，重置初始化標記
    window._workDraftInitialized = false;
  }
  return false;
}

// 立即嘗試初始化（如果元素已存在）
if (!tryInitWorkDraft()) {
  // 如果元素不存在，等待 DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      tryInitWorkDraft();
    }, { once: true });
  } else {
    // DOM 已就緒但元素可能還沒載入（透過 AJAX 載入），延遲再試
    // 使用多層次的檢查機制，確保能捕捉到動態載入的內容
    let attempts = 0;
    const maxAttempts = 20; // 最多嘗試 20 次（約 2 秒）
    
    const checkInterval = setInterval(() => {
      attempts++;
      if (tryInitWorkDraft() || attempts >= maxAttempts) {
        clearInterval(checkInterval);
      }
    }, 100);
    
    // 同時使用 MutationObserver 監聽 DOM 變化（更即時）
    const observer = new MutationObserver(() => {
      if (tryInitWorkDraft()) {
        observer.disconnect();
        clearInterval(checkInterval);
      }
    });
    const targetEl = document.body || document.documentElement;
    if (targetEl && targetEl instanceof Node) {
        try {
            observer.observe(targetEl, {
                childList: true,
                subtree: true
            });
        } catch (e) {
            console.warn('無法觀察 DOM 元素:', e);
        }
    }
    
    // 10 秒後停止觀察和檢查（避免記憶體洩漏）
    setTimeout(() => {
        observer.disconnect();
      clearInterval(checkInterval);
    }, 10000);
  }
}

// 監聽自定義事件（當頁面動態載入完成時）
$(document).on('pageLoaded scriptExecuted', function(e, path) {
  if (path && path.includes('work_draft')) {
    setTimeout(() => {
      if (!tryInitWorkDraft()) {
        // 如果第一次失敗，再試一次
        setTimeout(tryInitWorkDraft, 300);
      }
    }, 200);
  }
});
