// 動態解析 API URL（支援動態載入）
function resolveWorkDraftApiUrl() {
  const path = window.location.pathname || '';
  if (path.includes('/pages/')) {
    return 'work_draft_data.php';
  }
  return 'pages/work_draft_data.php';
}

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
  const whoSelect = filterForm?.querySelector('select[name="who"]');
  const fromInput = filterForm?.querySelector('input[name="from"]');
  const toInput = filterForm?.querySelector('input[name="to"]');

  let showAuthor = false;
  let currentPage = 1;
  let totalPages = 1;
  let currentWorkId = null;

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
    html += '<th style="width:160px">時間</th>';
    if (showAuthorFlag) {
      html += '<th style="width:160px">提交者</th>';
    }
    html += '<th style="width:260px">標題</th>';
    html += '<th style="width:250px">內容預覽</th>'; // 為內容預覽列設置寬度
    html += '<th style="width:180px">狀態</th>';
    html += '<th style="width:120px">留言</th>';

    const theadRow = document.getElementById('work-thead-row');
    if (theadRow) {
      theadRow.innerHTML = html;
    }
  }

  function renderRows(rows) {
    const hasAuthor = showAuthor;
    const colspan = hasAuthor ? 7 : 6;

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
        ? `<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#commentModal" data-id="${r.work_ID}">留言</button>`
        : '<span class="text-muted">－</span>';

      // const viewBtn = `
      //   <button type="button" class="btn btn-sm btn-outline-secondary"
      //           data-bs-toggle="modal"
      //           data-bs-target="#viewModal"
      //           data-title="${title}"
      //           data-date="${dateLabel}"
      //           data-content="${content}">
      //     查看
      //   </button>
      // `;

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
    
    // 確保按鈕不會被禁用
    tbody.querySelectorAll('.btn').forEach(btn => {
      btn.disabled = false;
      btn.removeAttribute('disabled');
    });
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

  async function loadList(page = 1) {
    try {
      const params = new URLSearchParams({ action: 'list', page });
      if (whoSelect?.value) params.set('who', whoSelect.value);
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
        throw new Error(j.error || '載入失敗');
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
      if (whoSelect && j.me && j.teamMembers) {
        const currentWho = (j.filter && j.filter.who) || 'me';
        updateWhoOptions(j.me, j.teamMembers, currentWho);
      }

      // 如果後端因為參數修正過 who，也同步更新
      if (whoSelect && j.filter && j.filter.who && whoSelect.value !== j.filter.who) {
        whoSelect.value = j.filter.who;
      }

      showAuthor = !!j.showAuthor;
      renderTableHead(showAuthor);
      renderRows(j.rows || []);
      currentPage = j.page || 1;
      totalPages = j.pages || 1;
      buildPager(currentPage, totalPages);
    } catch (err) {
      console.error('work-draft loadList error:', err);
      const colspan = showAuthor ? 7 : 6;
      tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-danger text-center">資料載入失敗</td></tr>`;
      pager.innerHTML = '<span class="disabled">1</span>';
    }
  }

  // 篩選表單送出
  filterForm?.addEventListener('submit', e => {
    e.preventDefault();
    loadList(1);
  });

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
    const listBox = commentModal.querySelector('#cmn-list');
    const textArea = commentModal.querySelector('#cmn-text');
    const submitBtn = commentModal.querySelector('#cmn-submit');
    
    if (!submitBtn) {
      console.error('Submit button not found in comment modal');
      return;
    }

    commentModal.addEventListener('show.bs.modal', async e => {
      const button = e.relatedTarget;
      if (!button) return;
      currentWorkId = button.getAttribute('data-id');
      if (!currentWorkId) return;

      listBox.textContent = '載入中...';
      textArea.value = '';

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
          return `<div><b>${name}</b>：${text}</div>`;
        }).join('');
      } catch (err) {
        console.error(err);
        listBox.textContent = '讀取失敗';
      }
    });

    submitBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      const t = textArea.value.trim();
      if (!t || !currentWorkId) return;

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
        if (!j.ok) return;

        if (!j.comments || j.comments.length === 0) {
          listBox.textContent = '尚無留言';
          textArea.value = '';
          return;
        }

        listBox.innerHTML = j.comments.map(c => {
          const name = escapeHtml(c.name || c.uid || '');
          const text = escapeHtml(c.text || '');
          return `<div><b>${name}</b>：${text}</div>`;
        }).join('');
        textArea.value = '';
      } catch (err) {
        console.error(err);
      }
    });
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
    observer.observe(document.body || document.documentElement, {
      childList: true,
      subtree: true
    });
    
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
