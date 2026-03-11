// integrate.js
// 使用局部变量避免覆盖全局 jQuery
(function() {
  'use strict';
  
  // 使用局部变量 $query 代替 $，避免覆盖全局 jQuery
  const $query = s => document.querySelector(s);
  
  const gridBody = $query('#gridBody');
  const cohortSelect = $query('#cohort');
  const titleInput = $query('#title');
  const formatSelect = $query('#format');
  const btnCreate = $query('#btnCreate');

  // 獲取當前用戶角色（從頁面全局變量獲取）
  const currentRole = window.currentUserRole || null;
  const isConvener = window.isConvener || false;
  const isOffice = window.isOffice || false;

  // 轉義 HTML 字符
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // 等待 SweetAlert2 載入的輔助函數
  function waitForSwal(maxWait = 5000) {
    return new Promise((resolve) => {
      if (window.Swal) {
        resolve(true);
        return;
      }
      
      let elapsed = 0;
      const checkInterval = 100;
      const timer = setInterval(() => {
        elapsed += checkInterval;
        if (window.Swal) {
          clearInterval(timer);
          resolve(true);
        } else if (elapsed >= maxWait) {
          clearInterval(timer);
          resolve(false);
        }
      }, checkInterval);
    });
  }

  // 顯示提示彈跳視窗（使用 SweetAlert2）
  // options: { timer: 毫秒 } 則不顯示確定按鈕，時間到自動關閉
  // options: { toast: true, position: 'bottom-end' } 則以右下角 toast 顯示，較快出現且自動關閉
  async function showAlertDialog(message, type = 'info', options = {}) {
    // 等待 SweetAlert2 載入
    const swalLoaded = await waitForSwal();
    
    if (!swalLoaded || !window.Swal) {
      // 如果 SweetAlert2 未載入，使用原生 alert
      alert(message);
      return Promise.resolve();
    }

    const iconMap = {
      info: 'info',
      success: 'success',
      error: 'error',
      warning: 'warning'
    };

    const icon = iconMap[type] || 'info';

    // 右下角 toast：顯示快、自動關閉
    if (options.toast) {
      const toastTimer = options.timer || 1500;
      const Toast = Swal.mixin({
        toast: true,
        position: options.position || 'bottom-end',
        showConfirmButton: false,
        timer: toastTimer,
        timerProgressBar: true,
        didOpen: (toast) => {
          toast.addEventListener('mouseenter', Swal.stopTimer);
          toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
      });
      return Toast.fire({ icon, title: '提示', text: message });
    }

    const opts = {
      icon,
      title: '提示',
      text: message,
      confirmButtonText: '確定',
      confirmButtonColor: '#667eea',
      customClass: {
        confirmButton: 'swal2-confirm-integrate'
      }
    };

    if (options.timer && options.timer > 0) {
      opts.showConfirmButton = false;
      opts.timer = options.timer;
      opts.timerProgressBar = true;
    }

    return Swal.fire(opts);
  }

  // 顯示確認彈跳視窗（使用 SweetAlert2）
  async function showConfirmDialog(message, title = '確認操作') {
    // 等待 SweetAlert2 載入
    const swalLoaded = await waitForSwal();
    
    if (!swalLoaded || !window.Swal) {
      // 如果 SweetAlert2 未載入，使用原生 confirm
      return Promise.resolve(confirm(message));
    }

    return Swal.fire({
      title: title,
      text: message,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: '確定',
      cancelButtonText: '取消',
      confirmButtonColor: '#667eea',
      cancelButtonColor: '#6c757d',
      reverseButtons: false,
      customClass: {
        confirmButton: 'swal2-confirm-integrate',
        cancelButton: 'swal2-cancel-integrate'
      }
    }).then((result) => {
      return result.isConfirmed;
    });
  }

// 獲取 API 路徑
function getApiPath() {
  const pathname = window.location.pathname;
  const hash = window.location.hash;
  
  // 如果通過 main.php#pages/integrate.php 加載
  if (pathname.includes('main.php') || hash.includes('pages/')) {
    return 'pages/integrate_data.php';
  }
  
  // 如果直接在 pages/ 目錄下訪問
  if (pathname.includes('/pages/')) {
    return 'integrate_data.php';
  }
  
  // 默認情況
  return 'pages/integrate_data.php';
}

const API_PATH = getApiPath();

// 載入屆別資料
async function loadCohorts() {
  try {
    const res = await fetch(`${API_PATH}?action=getCohorts`);
    const json = await res.json();
    if (json.ok && json.data) {
      cohortSelect.innerHTML = '<option value="">全部</option>' + 
        json.data.map(c => `<option value="${c.cohort_ID}">${c.cohort_name || c.cohort_ID}</option>`).join('');
    }
  } catch (e) {
    console.error('載入屆別失敗:', e);
  }
}

// 查詢標題（當用戶輸入時）
let searchTimeout = null;
titleInput.addEventListener('input', function() {
  const keyword = this.value.trim();
  const cohort_ID = cohortSelect.value;
  const format = formatSelect.value;
  
  if (searchTimeout) {
    clearTimeout(searchTimeout);
  }
  
  // 延遲500ms後查詢，避免頻繁請求
  searchTimeout = setTimeout(async () => {
    if (keyword.length > 0 && cohort_ID) {
      await searchTitles(keyword, cohort_ID, format);
    }
  }, 500);
});

// 查詢標題列表
async function searchTitles(keyword, cohort_ID, format) {
  try {
    const params = new URLSearchParams({
      action: 'searchTitles',
      keyword: keyword,
      cohort_ID: cohort_ID || '',
      format: format || ''
    });
    
    const res = await fetch(`${API_PATH}?${params}`);
    const json = await res.json();
    
    if (json.ok && json.data && json.data.length > 0) {
      // 這裡可以實現自動完成功能，暫時先不做
      // 用戶可以手動選擇或繼續輸入
    }
  } catch (e) {
    console.error('查詢標題失敗:', e);
  }
}

// 監聽資料類型變化，當選擇"全部"時禁用建立按鈕
formatSelect.addEventListener('change', function() {
  if (this.value === '全部') {
    btnCreate.disabled = true;
    btnCreate.style.opacity = '0.5';
    btnCreate.style.cursor = 'not-allowed';
  } else if (this.value === '時程表' && isConvener) {
    // 召集人不能建立時程表
    btnCreate.disabled = true;
    btnCreate.style.opacity = '0.5';
    btnCreate.style.cursor = 'not-allowed';
  } else if (isOffice && (this.value === '審查建議表' || this.value === '初審建議表')) {
    // 科辦只能建立時程表，審查建議表與初審建議表僅能察看與編輯
    btnCreate.disabled = true;
    btnCreate.style.opacity = '0.5';
    btnCreate.style.cursor = 'not-allowed';
  } else {
    btnCreate.disabled = false;
    btnCreate.style.opacity = '1';
    btnCreate.style.cursor = 'pointer';
  }
});

// 頁面載入時同步建立按鈕狀態（依目前資料類型與角色）
if (formatSelect) formatSelect.dispatchEvent(new Event('change'));

// 載入列表
async function loadList() {
  try {
    const params = new URLSearchParams({
      action: 'list',
      cohort: cohortSelect.value || '',
      title: titleInput.value.trim() || '',
      format: formatSelect.value || ''
    });
    
    const res = await fetch(`${API_PATH}?${params}`);
    const json = await res.json();
    
    if (json.ok) {
      render(json.data || []);
    } else {
      await showAlertDialog(json.msg || '查詢失敗', 'error');
      render([]);
    }
  } catch (e) {
    console.error('載入列表失敗:', e);
    render([]);
  }
}

function render(rows) {
  if (!rows.length) {
    gridBody.innerHTML = `<tr><td colspan="6" class="empty">尚無資料</td></tr>`;
    // 隱藏导出按钮
    const exportPDFBtn = document.getElementById('btnExportPDF');
    const exportWordBtn = document.getElementById('btnExportWord');
    if (exportPDFBtn) exportPDFBtn.style.display = 'none';
    if (exportWordBtn) exportWordBtn.style.display = 'none';
    return;
  }

  gridBody.innerHTML = rows.map((r, index) => `
    <tr data-index="${index}" data-id="${r.id || ''}" data-format="${r.format || ''}" data-title="${escapeHtml(r.title || '')}" data-cohort-id="${r.cohort_ID || ''}">
      <td class="center">
        <input type="checkbox" class="row-checkbox" data-id="${r.id || ''}" data-format="${r.format || ''}" data-title="${escapeHtml(r.title || '')}" data-cohort-id="${r.cohort_ID || ''}">
      </td>
      <td>${r.title || ''}</td>
      <td>${r.cohort || ''}</td>
      <td>${r.format || ''}</td>
      <td>${r.editor || ''}${r.edit_time ? ' ' + r.edit_time : ''}</td>
      <td class="center">
        <button class="op-btn btn-edit" data-title="${(r.title || '').replace(/"/g, '&quot;')}" data-cohort="${r.cohort_ID || ''}" data-cohort-name="${(r.cohort || '').replace(/"/g, '&quot;')}" data-format="${r.format || ''}" data-id="${r.id || ''}">編輯</button>
        ${isOffice ? '<button type="button" class="op-btn btn-publish" data-format="' + (r.format || '') + '" data-id="' + (r.id || '') + '">發佈</button>' : ''}
      </td>
    </tr>
  `).join('');
  
  // 綁定編輯按鈕事件
  gridBody.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', handleEditClick);
  });
  
  // 綁定發布按鈕事件（只有科辦可以看到和點擊）
  gridBody.querySelectorAll('.btn-publish').forEach(btn => {
    btn.addEventListener('click', handlePublishClick);
  });
  
  // 綁定 checkbox 事件
  bindCheckboxEvents();
  
  // 更新导出按钮显示状态
  updateExportButtons();
}

// 處理編輯按鈕點擊
function handleEditClick(e) {
  const btn = e.target;
  const title = btn.getAttribute('data-title');
  const cohort_ID = btn.getAttribute('data-cohort');
  const cohort_name = btn.getAttribute('data-cohort-name');
  const format = btn.getAttribute('data-format');
  const id = btn.getAttribute('data-id');
  
  // 直接跳轉到相應的編輯頁面
  navigateToEditPage(title, cohort_ID, format, id);
}

// 跳轉到編輯頁面
async function navigateToEditPage(title, cohort_ID, format, id) {
  try {
    // 獲取基礎路徑
    const pathname = window.location.pathname;
    const hash = window.location.hash;
    let basePath = '';
    
    // 判斷是否通過 main.php 載入
    if (pathname.includes('main.php')) {
      // 通過 main.php 載入，改為「完整重新載入 + hash」，避免必須手動重新整理才初始化腳本
      const params = new URLSearchParams();
      if (cohort_ID) {
        params.append('cohort_ID', cohort_ID);
      }
      if (title) {
        params.append('title', title);
      }
      if (id) {
        if (format === '時程表') {
          params.append('tinforma_ID', id);
        } else if (format === '審查建議表' || format === '初審建議表') {
          params.append('sf_ID', id);
        }
      }
      params.append('from_integrate', '1');
      
      let targetPage = '';
      if (format === '時程表') {
        targetPage = 'pages/schedule_manage.php';
      } else if (format === '審查建議表' || format === '初審建議表') {
        targetPage = 'pages/suggest.php';
      }
      
      const hashPart = `${targetPage}?${params.toString()}`;
      const basePath = pathname.split('?')[0] || 'main.php';
      const ts = Date.now();
      // 透過變更查詢字串強制重新載入 main.php，避免舊的 page-script 狀態殘留
      window.location.href = `${basePath}?_reload=${ts}#${hashPart}`;
    } else {
      // 直接訪問，使用 window.location.href 跳轉
      const params = new URLSearchParams();
      if (cohort_ID) {
        params.append('cohort_ID', cohort_ID);
      }
      if (title) {
        params.append('title', title);
      }
      if (id) {
        if (format === '時程表') {
          params.append('tinforma_ID', id);
        } else if (format === '審查建議表' || format === '初審建議表') {
          params.append('sf_ID', id);
        }
      }
      params.append('from_integrate', '1');
      
      let targetPage = '';
      if (format === '時程表') {
        targetPage = 'pages/schedule_manage.php';
      } else if (format === '審查建議表' || format === '初審建議表') {
        targetPage = 'pages/suggest.php';
      }
      
      window.location.href = `${targetPage}?${params.toString()}`;
    }
  } catch (e) {
    console.error('跳轉失敗:', e);
    await showAlertDialog('跳轉失敗：' + e.message, 'error');
  }
}

// 處理發布按鈕點擊
async function handlePublishClick(e) {
  e.preventDefault();
  const btn = e.target;
  const row = btn.closest('tr');
  const format = btn.getAttribute('data-format');
  const id = btn.getAttribute('data-id');
  const cohort_ID = row ? row.getAttribute('data-cohort-id') : '';
  const title = row ? row.getAttribute('data-title') : '';

  if (!format || !id || !cohort_ID || !title) {
    await showAlertDialog('資料不完整，無法發布', 'warning');
    return;
  }

  // 只有科辦可以發布
  if (!isOffice) {
    await showAlertDialog('只有科辦可以發布', 'warning');
    return;
  }

  const confirmed = await showConfirmDialog(
    '確定要發布「' + format + '」嗎？發布後將發送通知給當屆所有人（含 Gmail）。',
    '確認發佈'
  );
  if (!confirmed) {
    return;
  }

  try {
    const fd = new FormData();
    fd.append('action', 'publish');
    fd.append('format', format);
    fd.append('id', id);
    fd.append('cohort_ID', cohort_ID);
    fd.append('title', title);

    const res = await fetch(API_PATH, { method: 'POST', body: fd });
    const text = await res.text();
    let json;
    try {
      json = JSON.parse(text);
    } catch (parseErr) {
      console.error('發布回應非 JSON:', text.slice(0, 200));
      await showAlertDialog('發布失敗：伺服器回傳格式錯誤（可能為 PHP 錯誤），請檢查伺服器紀錄或聯絡管理員。', 'error');
      return;
    }

    // 在 Console 中顯示完整回應，方便除錯
    console.log('integrate publish response:', json);

    if (json.ok) {
      const swalLoaded = await waitForSwal();
      if (swalLoaded && window.Swal) {
        Swal.fire({
          icon: 'success',
          title: '發佈成功',
          text: json.msg || '已發佈並通知當屆所有人。',
          confirmButtonText: '確定',
          confirmButtonColor: '#667eea'
        });
      } else {
        alert(json.msg || '發布成功');
      }
      loadList();
    } else {
      const swalLoaded = await waitForSwal();
      if (swalLoaded && window.Swal) {
        Swal.fire({
          icon: 'error',
          title: '發佈失敗',
          text: json.msg || '發布失敗',
          confirmButtonText: '確定',
          confirmButtonColor: '#667eea'
        });
      } else {
        alert(json.msg || '發布失敗');
      }
    }
  } catch (err) {
    console.error('發布失敗:', err);
    await showAlertDialog('發布失敗：' + (err.message || String(err)), 'error');
  }
}

// 建立按鈕點擊事件
btnCreate.addEventListener('click', async () => {
  const cohort = cohortSelect.value;
  const title = titleInput.value.trim();
  const format = formatSelect.value;
  
  if (!cohort) {
    await showAlertDialog('請選擇屆別', 'warning');
    return;
  }
  
  if (!title) {
    await showAlertDialog('請輸入標題', 'warning');
    return;
  }
  
  if (!format || format === '全部') {
    await showAlertDialog('請選擇資料類型（「全部」僅供查詢）', 'warning');
    return;
  }
  
  // 檢查權限：時程表只有科辦可以建立；初審建議表只有召集人可以建立；科辦只能建立時程表
  if (format === '時程表' && isConvener) {
    await showAlertDialog('時程表只有科辦可以建立', 'warning');
    return;
  }
  if (format === '初審建議表' && !isConvener) {
    await showAlertDialog('只有召集人可以建立初審建議表', 'warning');
    return;
  }
  if (isOffice && (format === '審查建議表' || format === '初審建議表')) {
    await showAlertDialog('科辦的權限只能建立時程表', 'warning');
    return;
  }
  
  const confirmed = await showConfirmDialog(`確定要建立「${format}」：${title} 嗎？`, '確認建立');
  if (!confirmed) {
    return;
  }
  
  try {
    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('title', title);
    fd.append('cohort', cohort);
    fd.append('format', format);

    const res = await fetch(API_PATH, {
      method: 'POST',
      body: fd
    });
    const json = await res.json();
    
    if (json.ok) {
      await showAlertDialog(json.msg || '建立成功', 'success', { toast: true, position: 'bottom-end', timer: 800 });
      // 清空輸入欄位
      titleInput.value = '';
      // 重新載入列表
      loadList();
    } else {
      await showAlertDialog(json.msg || '建立失敗', 'error');
    }
  } catch (e) {
    console.error('建立失敗:', e);
    await showAlertDialog('建立失敗：' + e.message, 'error');
  }
});

// 查詢按鈕點擊事件
$query('#btnSearch').addEventListener('click', loadList);

// 匯出歷次建議按鈕點擊事件（依目前篩選的屆別匯出該屆全部建議）
$query('#btnExportHistory').addEventListener('click', function() {
  const cohortId = cohortSelect.value;
  if (!cohortId) {
    showAlertDialog('請先選擇屆別', 'warning');
    return;
  }
  const url = `pages/suggest_export_history.php?cohort_ID=${encodeURIComponent(cohortId)}&format=word`;
  window.open(url, '_blank');
});

// ========================
// Checkbox 相關功能
// ========================

// 綁定 checkbox 事件
function bindCheckboxEvents() {
  // 全选 checkbox
  const selectAll = document.getElementById('selectAll');
  if (selectAll) {
    selectAll.addEventListener('change', function() {
      const checkboxes = gridBody.querySelectorAll('.row-checkbox');
      checkboxes.forEach(cb => {
        cb.checked = this.checked;
      });
      updateExportButtons();
    });
  }
  
  // 单个 checkbox
  gridBody.querySelectorAll('.row-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
      updateSelectAllState();
      updateExportButtons();
    });
  });
}

// 更新全选 checkbox 状态
function updateSelectAllState() {
  const selectAll = document.getElementById('selectAll');
  if (!selectAll) return;
  
  const checkboxes = gridBody.querySelectorAll('.row-checkbox');
  const checkedCount = gridBody.querySelectorAll('.row-checkbox:checked').length;
  
  if (checkboxes.length === 0) {
    selectAll.checked = false;
    selectAll.indeterminate = false;
  } else if (checkedCount === 0) {
    selectAll.checked = false;
    selectAll.indeterminate = false;
  } else if (checkedCount === checkboxes.length) {
    selectAll.checked = true;
    selectAll.indeterminate = false;
  } else {
    selectAll.checked = false;
    selectAll.indeterminate = true;
  }
}

// 獲取選中的項目
function getSelectedItems() {
  const selected = [];
  gridBody.querySelectorAll('.row-checkbox:checked').forEach(cb => {
    const row = cb.closest('tr');
    if (row) {
      selected.push({
        id: cb.getAttribute('data-id'),
        format: cb.getAttribute('data-format'),
        title: cb.getAttribute('data-title'),
        cohort_ID: cb.getAttribute('data-cohort-id')
      });
    }
  });
  return selected;
}

// 更新导出按钮显示状态
function updateExportButtons() {
  const selected = getSelectedItems();
  const exportPDFBtn = document.getElementById('btnExportPDF');
  const exportWordBtn = document.getElementById('btnExportWord');
  
  if (selected.length > 0) {
    if (exportPDFBtn) exportPDFBtn.style.display = 'inline-block';
    if (exportWordBtn) exportWordBtn.style.display = 'inline-block';
  } else {
    if (exportPDFBtn) exportPDFBtn.style.display = 'none';
    if (exportWordBtn) exportWordBtn.style.display = 'none';
  }
}

// ========================
// 导出功能
// ========================

// 直接下載檔案（使用新視窗打開，讓瀏覽器自動處理下載）
function downloadFile(url) {
  return new Promise((resolve) => {
    console.log('開始下載:', url);
    
    // 使用 window.open 打開匯出頁面，瀏覽器會自動處理下載
    // 對於 PDF/Word 檔案，瀏覽器會自動下載而不會在新標籤頁顯示
    const newWindow = window.open(url, '_blank');
    
    // 如果瀏覽器阻擋了彈窗，嘗試使用 iframe
    if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
      console.log('彈窗被阻擋，使用 iframe 方式');
      try {
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.style.visibility = 'hidden';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = 'none';
        iframe.style.position = 'absolute';
        iframe.style.left = '-9999px';
        iframe.style.top = '-9999px';
        iframe.src = url;
        document.body.appendChild(iframe);
        
        // 監聽載入完成
        iframe.onload = function() {
          console.log('iframe 載入完成');
          // 等待 PDF 生成完成
          setTimeout(() => {
            if (document.body.contains(iframe)) {
              document.body.removeChild(iframe);
            }
            resolve();
          }, 3000);
        };
        
        // 超時處理
        setTimeout(() => {
          if (document.body.contains(iframe)) {
            document.body.removeChild(iframe);
          }
          resolve();
        }, 10000);
      } catch (e) {
        console.error('iframe 方式失敗:', e);
        resolve(); // 即使失敗也 resolve，避免阻塞
      }
    } else {
      // 成功打開新視窗
      resolve();
    }
  });
}

// 匯出 PDF（批次匯出選中的項目）
async function handleExportPDF() {
  const selected = getSelectedItems();
  
  if (selected.length === 0) {
    await showAlertDialog('請至少選擇一個項目', 'warning');
    return;
  }
  
  // 確認導出
  const confirmed = await showConfirmDialog(
    `確定要匯出 ${selected.length} 個項目為 PDF 嗎？`,
    '確認匯出 PDF'
  );
  
  if (!confirmed) {
    return;
  }
  
  try {
    // 根據格式分別處理
    const scheduleItems = selected.filter(item => item.format === '時程表');
    const suggestItems = selected.filter(item => item.format === '審查建議表' || item.format === '初審建議表');
    
    let exportedCount = 0;
    let delay = 0;
    
    // 匯出時程表
    if (scheduleItems.length > 0) {
      for (const item of scheduleItems) {
        if (item.id && item.cohort_ID) {
          // 直接下載時程表 PDF（延遲執行以避免瀏覽器阻擋多個下載）
          setTimeout(async () => {
            try {
              const url = `pages/schedule_export.php?cohort_ID=${item.cohort_ID}&tinforma_ID=${item.id}&download=1`;
              await downloadFile(url);
            } catch (e) {
              console.error('下載時程表 PDF 失敗:', e);
            }
          }, delay);
          delay += 800; // 每個檔案延遲 800ms，給 PDF 生成更多時間
          exportedCount++;
        }
      }
    }
    
    // 匯出建議表
    if (suggestItems.length > 0) {
      for (const item of suggestItems) {
        if (item.id && item.cohort_ID && item.title) {
          // 直接下載建議表 PDF（使用 group_ID=all 匯出全部類組）
          setTimeout(async () => {
            try {
              const url = `pages/suggest_export.php?cohort_ID=${item.cohort_ID}&group_ID=all&title=${encodeURIComponent(item.title)}`;
              await downloadFile(url);
            } catch (e) {
              console.error('下載建議表 PDF 失敗:', e);
            }
          }, delay);
          delay += 800; // 每個檔案延遲 800ms，給 PDF 生成更多時間
          exportedCount++;
        }
      }
    }
    
    if (exportedCount > 0) {
      await showAlertDialog(`已開始下載 ${exportedCount} 個 PDF 檔案`, 'success');
    }
    
  } catch (e) {
    console.error('匯出 PDF 失敗:', e);
    await showAlertDialog('匯出 PDF 失敗：' + e.message, 'error');
  }
}

// 匯出 Word
async function handleExportWord() {
  const selected = getSelectedItems();
  
  if (selected.length === 0) {
    await showAlertDialog('請至少選擇一個項目', 'warning');
    return;
  }
  
  // 確認導出
  const confirmed = await showConfirmDialog(
    `確定要匯出 ${selected.length} 個項目為 Word 嗎？`,
    '確認匯出 Word'
  );
  
  if (!confirmed) {
    return;
  }
  
  try {
    // 根據格式分別處理
    const scheduleItems = selected.filter(item => item.format === '時程表');
    const suggestItems = selected.filter(item => item.format === '審查建議表' || item.format === '初審建議表');
    
    let exportedCount = 0;
    let delay = 0;
    
    // 匯出時程表
    if (scheduleItems.length > 0) {
      for (const item of scheduleItems) {
        if (item.id && item.cohort_ID) {
          // 直接下載時程表 Word（延遲執行以避免瀏覽器阻擋多個下載）
          setTimeout(async () => {
            try {
              const url = `pages/schedule_export_word.php?cohort_ID=${item.cohort_ID}&tinforma_ID=${item.id}`;
              await downloadFile(url);
            } catch (e) {
              console.error('下載時程表 Word 失敗:', e);
            }
          }, delay);
          delay += 500; // Word 檔案生成較快，延遲 500ms 即可
          exportedCount++;
        }
      }
    }
    
    // 匯出建議表
    if (suggestItems.length > 0) {
      for (const item of suggestItems) {
        if (item.id && item.cohort_ID && item.title) {
          // 直接下載建議表 Word（使用 group_ID=all 匯出全部類組）
          setTimeout(async () => {
            try {
              const url = `pages/suggest_export_word.php?cohort_ID=${item.cohort_ID}&group_ID=all&title=${encodeURIComponent(item.title)}`;
              await downloadFile(url);
            } catch (e) {
              console.error('下載建議表 Word 失敗:', e);
            }
          }, delay);
          delay += 500; // Word 檔案生成較快，延遲 500ms 即可
          exportedCount++;
        }
      }
    }
    
    if (exportedCount > 0) {
      await showAlertDialog(`已開始下載 ${exportedCount} 個 Word 檔案`, 'success');
    }
    
  } catch (e) {
    console.error('匯出 Word 失敗:', e);
    await showAlertDialog('匯出 Word 失敗：' + e.message, 'error');
  }
}

// 綁定导出按钮事件
const exportPDFBtn = document.getElementById('btnExportPDF');
const exportWordBtn = document.getElementById('btnExportWord');

if (exportPDFBtn) {
  exportPDFBtn.addEventListener('click', handleExportPDF);
}

if (exportWordBtn) {
  exportWordBtn.addEventListener('click', handleExportWord);
}

// 初始化
loadCohorts();
loadList();
})(); // 立即执行函数，避免污染全局作用域
