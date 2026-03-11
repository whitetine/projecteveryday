// /js/pages/admin_notify.js
let currentEditId = null;

// API 路徑：main.php 載入時用 api.php；若在 pages/ 下則用 ../
function getNotifyApiBase() {
  const p = (window.location.pathname || '');
  return p.includes('/pages/') ? '../api.php' : 'api.php';
}

// 使用獨立名稱，避免覆寫 app.js 的 initPageScript 導致其他頁面初始化錯誤
window.initAdminNotifyPage = function () {
  if (!document.querySelector('.admin-notify-container')) return;
  // 頁面載入時清理可能殘留的 modal backdrop
  (function cleanupNotifyModal() {
    document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
  })();

  // 將 #notifyModal 移到 body，避免被 #content 的 stacking context 影響導致 backdrop 覆蓋 modal 本身
  const notifyModalEl = document.getElementById('notifyModal');
  if (notifyModalEl && notifyModalEl.parentElement !== document.body) {
    document.body.appendChild(notifyModalEl);
  }

  let htmlInited = false;
  function ensureHtmlEditor() {
    if (htmlInited) return;
    if (!window.$ || !$.fn || !$.fn.summernote) return;
    $('#htmlEditor').summernote({
      height: 260,
      placeholder: '輸入 HTML 內容...',
      toolbar: [
        ['style', ['bold','italic','underline','clear']],
        ['para', ['ul','ol','paragraph']],
        ['insert', ['link','picture','table']],
        ['view', ['codeview']]
      ]
    });
    htmlInited = true;
  }

  $('input[name="content_mode"]').off('change.notify').on('change.notify', function () {
    const mode = $(this).val();
    if (mode === 'text') {
      $('#plainTextarea').removeClass('d-none');
      $('#htmlEditor').addClass('d-none');
    } else {
      ensureHtmlEditor();
      $('#plainTextarea').addClass('d-none');
      $('#htmlEditor').removeClass('d-none');
    }
  });
  $('#plainTextarea').removeClass('d-none');
  $('#htmlEditor').addClass('d-none');

  async function submitNotify(closeAfter = false) {
    const $form = $('#notifyForm');
    
    // 驗證必填欄位
    const title = $form.find('input[name="title"]').val().trim();
    if (!title) {
      if (window.Swal) {
        Swal.fire({ 
          icon: 'warning', 
          title: '請輸入資訊名稱', 
          text: '資訊名稱為必填欄位' 
        });
      } else {
        alert('請輸入資訊名稱');
      }
      $form.find('input[name="title"]').focus();
      return;
    }
    
    const mode = $('input[name="content_mode"]:checked').val();
    const content = mode === 'text'
      ? $('#plainTextarea').val()
      : ($('#htmlEditor').hasClass('d-none') ? '' : ($('#htmlEditor').summernote ? $('#htmlEditor').summernote('code') : $('#htmlEditor').val()));

    const fd = new FormData($form[0]);
    fd.set('mode', mode);
    fd.set('content', content);

    // 如果是編輯模式，添加 msg_ID
    if (currentEditId) {
      fd.set('msg_ID', currentEditId);
    }

    try {
      const apiBase = getNotifyApiBase();
      const apiUrl = currentEditId 
        ? apiBase + '?do=notify_update' 
        : apiBase + '?do=notify_save';
      
      // 顯示載入中
      const submitBtn = closeAfter ? $('#btnSaveAndBack') : $('#btnSave');
      const originalText = submitBtn.html();
      submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>處理中...');
      
      const resp = await fetch(apiUrl, { method: 'POST', body: fd });
      
      // 檢查回應是否為 JSON
      const contentType = resp.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        const text = await resp.text();
        console.error('非 JSON 回應:', text.substring(0, 200));
        submitBtn.prop('disabled', false).html(originalText);
        if (window.Swal) {
          Swal.fire({ 
            icon: 'error', 
            title: '伺服器錯誤', 
            text: '伺服器回應格式錯誤，請檢查控制台' 
          });
        }
        return;
      }
      
      const json = await resp.json();
      
      // 恢復按鈕
      submitBtn.prop('disabled', false).html(originalText);
      
      if (json.ok) {
        const action = currentEditId ? '更新' : '新增';
        if (window.Swal) {
          Swal.fire({ 
            icon: 'success', 
            title: `已${action}`, 
            timer: 1500, 
            showConfirmButton: false 
          });
        }
        
        if (closeAfter) {
          const modal = bootstrap.Modal.getInstance(document.getElementById('notifyModal'));
          modal && modal.hide();
        } else {
          $form[0].reset();
          if ($('#htmlEditor').summernote) $('#htmlEditor').summernote('reset');
          $('#plainTextarea').removeClass('d-none');
          $('#htmlEditor').addClass('d-none');
          $('#modeText').prop('checked', true);
          currentEditId = null;
          
          // 更新 modal 標題
          $('#modalTitle').text('新增資訊');
          $('#btnSave').text('新增');
          $('#btnSaveAndBack').text('新增並返回');
        }
        
        // 重新載入列表
        if (typeof loadNotifyList === 'function') {
          loadNotifyList();
        }
      } else {
        if (window.Swal) {
          Swal.fire({ 
            icon: 'error', 
            title: '操作失敗', 
            text: json.msg || '請稍後再試' 
          });
        }
      }
    } catch (error) {
      console.error('提交錯誤:', error);
      if (window.Swal) {
        Swal.fire({ 
          icon: 'error', 
          title: '連線錯誤', 
          text: '無法連到伺服器' 
        });
      }
    }
  }

  $('#btnSave').off('click.notify').on('click.notify', () => submitNotify(false));
  $('#btnSaveAndBack').off('click.notify').on('click.notify', () => submitNotify(true));

  // 確保 Modal 元素存在後再綁定事件
  const notifyModalEl = document.getElementById('notifyModal');
  if (notifyModalEl) {
    notifyModalEl.addEventListener('show.bs.modal', () => {
      const $form = $('#notifyForm');
      $form[0].reset();
      if ($('#htmlEditor').summernote) $('#htmlEditor').summernote('reset');
      $('#plainTextarea').removeClass('d-none');
      $('#htmlEditor').addClass('d-none');
      $('#modeText').prop('checked', true);
      currentEditId = null;
      $('#modalTitle').text('新增資訊');
      $('#btnSave').text('新增');
      $('#btnSaveAndBack').text('新增並返回');
    });

    // Modal 關閉時強制清理 backdrop，避免殘留
    notifyModalEl.addEventListener('hidden.bs.modal', () => {
      document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
      document.body.classList.remove('modal-open');
      document.body.style.overflow = '';
      document.body.style.paddingRight = '';
    });

    // 確保 Bootstrap Modal 已初始化（使用 getOrCreateInstance 避免重複實例）
    if (window.bootstrap) {
      try {
        bootstrap.Modal.getOrCreateInstance(notifyModalEl, {
          backdrop: true,
          keyboard: true,
          focus: true
        });
      } catch (e) {
        console.warn('初始化 Bootstrap Modal 失敗:', e);
      }
    } else {
      console.warn('Bootstrap 未載入，Modal 可能無法正常運作');
    }
  } else {
    console.error('notifyModal 元素不存在，請檢查 modules/notify.php 是否正確包含');
  }
  
  // 初始化載入列表
  if (typeof window.loadNotifyList === 'function') {
    window.loadNotifyList();
  }
};

// 載入公告列表（確保在 window 上）
if (typeof window.loadNotifyList === 'undefined') {
  window.loadNotifyList = async function() {
  const container = document.getElementById('notifyListContent');
  if (!container) return;
  
  container.innerHTML = `
    <div class="text-center py-4">
      <div class="spinner-border text-primary" role="status">
        <span class="visually-hidden">載入中...</span>
      </div>
      <p class="mt-2 text-muted">載入中...</p>
    </div>
  `;
  
  try {
    const resp = await fetch(getNotifyApiBase() + '?do=notify_list');
    
    // 檢查回應是否為 JSON
    const contentType = resp.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await resp.text();
      console.error('非 JSON 回應:', text.substring(0, 200));
      container.innerHTML = '<div class="text-center py-4 text-danger">載入失敗：伺服器回應格式錯誤</div>';
      return;
    }
    
    const json = await resp.json();
    
    if (json.ok && json.list) {
      if (json.list.length === 0) {
        container.innerHTML = '<div class="text-center py-4 text-muted">尚無公告</div>';
        return;
      }
      
      let html = '<div class="notify-excel-table-wrapper"><table class="notify-excel-table">';
      html += '<thead><tr>';
      html += '<th>ID</th>';
      html += '<th>標題</th>';
      html += '<th>類型</th>';
      html += '<th>狀態</th>';
      html += '<th>優先級</th>';
      html += '<th>發佈時間</th>';
      html += '<th>到期時間</th>';
      html += '<th>建立者</th>';
      html += '<th>操作</th>';
      html += '</tr></thead><tbody>';
      
      json.list.forEach((item, idx) => {
        const typeMap = {
          'ANNOUNCEMENT': '公告',
          'SYSTEM_NOTICE': '系統通知',
          'REMINDER': '提醒'
        };
        const typeLabel = typeMap[item.msg_type] || item.msg_type;
        const statusClass = item.msg_status == 1 ? 'notify-status-normal' : 'notify-status-draft';
        const statusText = item.msg_status == 1 ? '已發布' : '未發布';
        
        const startDate = item.msg_start_d 
          ? new Date(item.msg_start_d).toLocaleString('zh-TW')
          : '-';
        const endDate = item.msg_end_d 
          ? new Date(item.msg_end_d).toLocaleString('zh-TW')
          : '-';
        
        const rowClass = idx % 2 === 1 ? 'notify-row-alt' : '';
        html += `<tr class="notify-table-row ${rowClass}" data-msg-id="${item.msg_ID}">`;
        html += `<td class="notify-id-cell">${item.msg_ID}</td>`;
        html += `<td class="notify-title-cell">${escapeHtml(item.msg_title || '')}</td>`;
        html += `<td class="notify-type-cell"><span class="notify-type-badge">${typeLabel}</span></td>`;
        html += `<td class="notify-status-cell"><span class="notify-status-badge ${statusClass}">${statusText}</span></td>`;
        html += `<td class="notify-priority-cell">${item.priority || 0}</td>`;
        html += `<td class="notify-date-cell">${startDate}</td>`;
        html += `<td class="notify-date-cell">${endDate}</td>`;
        html += `<td class="notify-creator-cell">${escapeHtml(item.creator_name || '')}</td>`;
        html += '<td class="notify-action-cell">';
        html += `<button type="button" class="btn-notify-view" onclick="event.stopPropagation(); if(typeof window.editNotification==='function'){window.editNotification(${item.msg_ID});}">編輯</button>`;
        if (item.msg_status == 0) {
          const safeTitle = escapeHtml(item.msg_title || '').replace(/'/g, "\\'");
          html += `<button type="button" class="btn-notify-view" onclick="event.stopPropagation(); if(typeof window.publishNotification==='function'){window.publishNotification(${item.msg_ID}, '${safeTitle}');}">發布</button>`;
        }
        const safeTitle = escapeHtml(item.msg_title || '').replace(/'/g, "\\'");
        html += `<button type="button" class="btn-notify-delete" onclick="event.stopPropagation(); if(typeof window.deleteNotification==='function'){window.deleteNotification(${item.msg_ID}, '${safeTitle}');}">刪除</button>`;
        html += '</td>';
        html += '</tr>';
      });
      
      html += '</tbody></table></div>';
      
      if (json.total > json.limit) {
        html += `<div class="mt-3 text-muted text-center">共 ${json.total} 筆資料</div>`;
      }
      
      container.innerHTML = html;
      
      // 為表格行添加點擊事件（點擊整行編輯）
      container.querySelectorAll('.notify-table-row').forEach(row => {
        row.addEventListener('click', function(e) {
          // 如果點擊的是操作按鈕區域，不觸發編輯
          if (e.target.closest('.notify-action-cell') || e.target.closest('button')) {
            return;
          }
          const msgId = this.getAttribute('data-msg-id');
          if (msgId && typeof window.editNotification === 'function') {
            window.editNotification(parseInt(msgId));
          }
        });
      });
    } else {
      container.innerHTML = `<div class="text-center py-4 text-danger">載入失敗：${json.msg || '未知錯誤'}</div>`;
    }
  } catch (error) {
    console.error('載入列表錯誤:', error);
    container.innerHTML = '<div class="text-center py-4 text-danger">載入失敗：連線錯誤 - ' + error.message + '</div>';
  }
  };
}

// 腳本載入時立即初始化（避免 app.js initPageScript 在腳本載入前執行導致 loadNotifyList 從未觸發）
if (document.querySelector('.admin-notify-container') && typeof window.initAdminNotifyPage === 'function') {
  window.initAdminNotifyPage();
}

// 確保 Modal 可以手動開啟（如果 data-bs-toggle 失效）
$(document).on('click', '[data-bs-target="#notifyModal"]', function(e) {
  e.preventDefault();
  const modalEl = document.getElementById('notifyModal');
  if (modalEl && window.bootstrap) {
    try {
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();
    } catch (err) {
      console.error('開啟 Modal 失敗:', err);
      // 如果 Bootstrap Modal 失敗，嘗試直接顯示
      $(modalEl).modal('show');
    }
  } else {
    console.error('無法開啟 Modal：元素不存在或 Bootstrap 未載入');
    if (!modalEl) {
      console.error('notifyModal 元素不存在');
    }
    if (!window.bootstrap) {
      console.error('Bootstrap 未載入');
    }
  }
});

// 編輯公告（確保在 window 上）
if (typeof window.editNotification === 'undefined') {
  window.editNotification = async function(msg_ID) {
    try {
      const resp = await fetch(getNotifyApiBase() + `?do=notify_detail&msg_ID=${msg_ID}`);
      const json = await resp.json();
      
      if (!json.ok || !json.detail) {
        if (window.Swal) {
          Swal.fire({ icon: 'error', title: '載入失敗', text: json.msg || '找不到該公告' });
        }
        return;
      }
      
      const detail = json.detail;
      if (typeof window.currentEditId !== 'undefined') {
        window.currentEditId = detail.msg_ID;
      } else {
        currentEditId = detail.msg_ID;
      }
      
      // 填入表單
      $('input[name="title"]').val(detail.msg_title || '');
      $('#plainTextarea').val(detail.msg_content || '');
      
      // 處理連結
      let linkUrl = '';
      if (detail.urls && Array.isArray(detail.urls)) {
        const linkItem = detail.urls.find(u => u.type === 'link');
        if (linkItem) {
          linkUrl = linkItem.url;
        }
      }
      $('input[name="link"]').val(linkUrl);
      
      // 設定狀態
      $(`input[name="status"][value="${detail.msg_status}"]`).prop('checked', true);
      
      // 設定優先級
      $('input[name="priority"]').val(detail.priority || 0);
      
      // 設定類型
      $('select[name="msg_type"]').val(detail.msg_type || 'ANNOUNCEMENT');
      
      // 設定日期
      if (detail.msg_start_d) {
        const startDate = new Date(detail.msg_start_d);
        const startLocal = new Date(startDate.getTime() - startDate.getTimezoneOffset() * 60000)
          .toISOString().slice(0, 16);
        $('input[name="start_dt"]').val(startLocal);
      }
      
      if (detail.msg_end_d) {
        const endDate = new Date(detail.msg_end_d);
        const endLocal = new Date(endDate.getTime() - endDate.getTimezoneOffset() * 60000)
          .toISOString().slice(0, 16);
        $('input[name="end_dt"]').val(endLocal);
      }
      
      // 更新 modal 標題和按鈕
      $('#modalTitle').text('編輯資訊');
      $('#btnSave').text('更新');
      $('#btnSaveAndBack').text('更新並返回');
      
      // 顯示 modal（使用 getOrCreateInstance 避免重複實例造成 backdrop 殘留）
      const modalEl = document.getElementById('notifyModal');
      if (modalEl && window.bootstrap) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
      } else if (window.$ && $.fn.modal) {
        $('#notifyModal').modal('show');
      }
      
    } catch (error) {
      console.error('編輯錯誤:', error);
      if (window.Swal) {
        Swal.fire({ icon: 'error', title: '載入失敗', text: '無法連到伺服器' });
      }
    }
  };
}

// 刪除公告（確保在 window 上）
if (typeof window.deleteNotification === 'undefined') {
  window.deleteNotification = function(msg_ID, title) {
  if (!window.Swal) {
    if (confirm(`確定要刪除「${title}」嗎？`)) {
      doDeleteNotification(msg_ID);
    }
    return;
  }
  
  Swal.fire({
    title: '確認刪除',
    html: `確定要刪除「<strong>${escapeHtml(title)}</strong>」嗎？<br><small class="text-muted">此操作無法復原</small>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: '確定刪除',
    cancelButtonText: '取消',
    confirmButtonColor: '#dc3545',
    reverseButtons: true
  }).then((result) => {
    if (result.isConfirmed) {
      doDeleteNotification(msg_ID);
    }
  });
  };
}

// 發布公告（確保在 window 上）
if (typeof window.publishNotification === 'undefined') {
  window.publishNotification = async function(msg_ID, title) {
    if (!window.Swal) {
      if (confirm(`確定要發布「${title}」嗎？`)) {
        doPublishNotification(msg_ID);
      }
      return;
    }
    
    Swal.fire({
      title: '確認發布',
      html: `確定要發布「<strong>${escapeHtml(title)}</strong>」嗎？`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: '確定發布',
      cancelButtonText: '取消',
      confirmButtonColor: '#28a745',
      reverseButtons: true
    }).then((result) => {
      if (result.isConfirmed) {
        doPublishNotification(msg_ID);
      }
    });
  };
}

async function doPublishNotification(msg_ID) {
  try {
    const resp = await fetch(getNotifyApiBase() + '?do=notify_publish', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: `msg_ID=${msg_ID}`
    });
    
    const json = await resp.json();
    
    if (json.ok) {
      if (window.Swal) {
        Swal.fire({ 
          icon: 'success', 
          title: '已發布', 
          timer: 1500, 
          showConfirmButton: false 
        });
      }
      if (typeof window.loadNotifyList === 'function') {
        window.loadNotifyList();
      }
    } else {
      if (window.Swal) {
        Swal.fire({ 
          icon: 'error', 
          title: '發布失敗', 
          text: json.msg || '請稍後再試' 
        });
      }
    }
  } catch (error) {
    console.error('發布錯誤:', error);
    if (window.Swal) {
      Swal.fire({ 
        icon: 'error', 
        title: '連線錯誤', 
        text: '無法連到伺服器' 
      });
    }
  }
}

async function doDeleteNotification(msg_ID) {
  try {
    const resp = await fetch(getNotifyApiBase() + '?do=notify_delete', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: `msg_ID=${msg_ID}`
    });
    
    const json = await resp.json();
    
    if (json.ok) {
      if (window.Swal) {
        Swal.fire({ 
          icon: 'success', 
          title: '已刪除', 
          timer: 1500, 
          showConfirmButton: false 
        });
      }
      loadNotifyList();
    } else {
      if (window.Swal) {
        Swal.fire({ 
          icon: 'error', 
          title: '刪除失敗', 
          text: json.msg || '請稍後再試' 
        });
      }
    }
  } catch (error) {
    console.error('刪除錯誤:', error);
    if (window.Swal) {
      Swal.fire({ 
        icon: 'error', 
        title: '連線錯誤', 
        text: '無法連到伺服器' 
      });
    }
  }
}

// HTML 轉義函數
function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}
