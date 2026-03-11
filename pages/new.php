
<div class="container-fluid py-3 gmail-notifications" data-page-id="admin_notify">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0" style="font-size: '40px';">最新消息</h1>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" id="refreshBtn" title="重新整理">
        <i class="bi bi-arrow-clockwise"></i> 重新整理
      </button>
      <div class="btn-group" role="group">
        <button type="button" class="btn btn-sm btn-outline-primary" id="markAllReadBtn" title="全部標記為已讀">
          <i class="bi bi-check-all"></i> 全部已讀
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" title="刪除選中的通知">
          <i class="bi bi-trash"></i> 刪除
        </button>
      </div>
    </div>
  </div>

  <!-- 工具欄 -->
  <div class="gmail-toolbar mb-3">
    <div class="d-flex align-items-center gap-3">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="selectAllCheckbox" title="全選">
        <label class="form-check-label" for="selectAllCheckbox"></label>
      </div>
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary" id="filterAll" data-filter="all">全部</button>
        <button type="button" class="btn btn-outline-secondary" id="filterUnread" data-filter="unread">未讀</button>
        <button type="button" class="btn btn-outline-secondary" id="filterRead" data-filter="read">已讀</button>
      </div>
      <div class="ms-auto">
        <span class="badge bg-primary" id="unreadCount">0</span> 未讀
      </div>
    </div>
  </div>

  <!-- 通知列表 -->
  <div class="gmail-notification-list" id="notificationList">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border" role="status">
        <span class="visually-hidden">載入中...</span>
      </div>
      <p class="mt-2">正在載入通知...</p>
    </div>
  </div>

  <!-- 空狀態 -->
  <div class="gmail-empty-state d-none" id="emptyState">
    <div class="text-center py-5">
      <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
      <p class="mt-3 text-muted">沒有通知</p>
    </div>
  </div>
</div>

<style>
.gmail-notifications {
  max-width: 1200px;
  margin: 0 auto;
}

.gmail-toolbar {
  padding: 0.75rem;
  background: #f8f9fa;
  border-radius: 8px;
  border: 1px solid #e0e0e0;
}

.gmail-notification-list {
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e0e0e0;
  overflow: hidden;
}

.gmail-notification-item {
  display: flex;
  align-items: flex-start;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #f0f0f0;
  cursor: pointer;
  transition: background-color 0.2s;
  position: relative;
}

.gmail-notification-item:hover {
  background-color: #f5f5f5;
  box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.gmail-notification-item.unread {
  background-color: #f8f9ff;
  font-weight: 500;
}

.gmail-notification-item.unread:hover {
  background-color: #f0f2ff;
}

.gmail-notification-item.selected {
  background-color: #e3f2fd;
}

.gmail-notification-item.selected.unread {
  background-color: #d1e7ff;
}

.gmail-notification-checkbox {
  margin-right: 0.75rem;
  margin-top: 0.25rem;
  flex-shrink: 0;
}

.gmail-notification-content {
  flex: 1;
  min-width: 0;
}

.gmail-notification-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.25rem;
}

.gmail-notification-title {
  font-size: 0.95rem;
  font-weight: 500;
  color: #202124;
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.gmail-notification-item.unread .gmail-notification-title {
  font-weight: 600;
}

.gmail-notification-time {
  font-size: 0.85rem;
  color: #5f6368;
  white-space: nowrap;
  margin-left: 1rem;
  flex-shrink: 0;
}

.gmail-notification-preview {
  font-size: 0.875rem;
  color: #5f6368;
  line-height: 1.4;
  margin-top: 0.25rem;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.gmail-notification-item.unread .gmail-notification-preview {
  color: #202124;
}

.gmail-notification-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-left: 0.75rem;
  opacity: 0;
  transition: opacity 0.2s;
  flex-shrink: 0;
}

.gmail-notification-item:hover .gmail-notification-actions {
  opacity: 1;
}

.gmail-notification-actions button {
  padding: 0.25rem 0.5rem;
  font-size: 0.8rem;
  border: none;
  background: transparent;
  color: #5f6368;
  cursor: pointer;
  border-radius: 4px;
  transition: background-color 0.2s;
}

.gmail-notification-actions button:hover {
  background-color: #e0e0e0;
}

.gmail-notification-actions .btn-mark-read {
  color: #1a73e8;
}

.gmail-notification-actions .btn-delete {
  color: #ea4335;
}

.gmail-empty-state {
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e0e0e0;
  min-height: 300px;
}

/* 響應式設計 */
@media (max-width: 768px) {
  .gmail-notification-item {
    padding: 0.5rem;
  }
  
  .gmail-notification-time {
    font-size: 0.75rem;
  }
  
  .gmail-notification-actions {
    opacity: 1;
  }
}
</style>

<script>
(function() {
  'use strict';
  
  // ⭐ SweetAlert Toast 設定（參考期中期末建議的右下角小型提示）
  const Toast = Swal.mixin({
    toast: true,
    position: "bottom-end",
    showConfirmButton: false,
    timer: 2000,
    timerProgressBar: true
  });
  
  let allNotifications = [];
  let filteredNotifications = [];
  let selectedNotifications = new Set();
  let currentFilter = 'all';
  
  const notificationList = document.getElementById('notificationList');
  const emptyState = document.getElementById('emptyState');
  const selectAllCheckbox = document.getElementById('selectAllCheckbox');
  const unreadCountBadge = document.getElementById('unreadCount');
  
  // 注意：查看鏈接現在直接跳轉到 msg_url 中的 URL，不需要事件處理
  
  // 載入通知
  async function loadNotifications() {
    try {
      notificationList.innerHTML = `
        <div class="text-center py-5 text-muted">
          <div class="spinner-border" role="status">
            <span class="visually-hidden">載入中...</span>
          </div>
          <p class="mt-2">正在載入通知...</p>
        </div>
      `;
      
      const response = await fetch('api.php?do=get_all_notifications');
      const data = await response.json();
      
      if (data.status === 'error') {
        throw new Error(data.message || '載入失敗');
      }
      
      allNotifications = Array.isArray(data) ? data : [];
      updateUnreadCount();
      applyFilter();
    } catch (error) {
      console.error('載入通知失敗:', error);
      notificationList.innerHTML = `
        <div class="text-center py-5 text-danger">
          <i class="bi bi-exclamation-triangle"></i>
          <p class="mt-2">載入失敗：${error.message}</p>
          <button class="btn btn-sm btn-primary mt-2" onclick="location.reload()">重新載入</button>
        </div>
      `;
      Toast.fire({ 
        icon: 'error', 
        title: '載入失敗',
        text: error.message || '請稍後再試'
      });
    }
  }
  
  // 更新未讀數量
  function updateUnreadCount() {
    const unreadCount = allNotifications.filter(n => !n.is_read).length;
    unreadCountBadge.textContent = unreadCount;
  }
  
  // 應用篩選
  function applyFilter() {
    if (currentFilter === 'unread') {
      filteredNotifications = allNotifications.filter(n => !n.is_read);
    } else if (currentFilter === 'read') {
      filteredNotifications = allNotifications.filter(n => n.is_read);
    } else {
      filteredNotifications = [...allNotifications];
    }
    
    renderNotifications();
  }
  
  // 渲染通知列表
  function renderNotifications() {
    if (filteredNotifications.length === 0) {
      notificationList.classList.add('d-none');
      emptyState.classList.remove('d-none');
      return;
    }
    
    notificationList.classList.remove('d-none');
    emptyState.classList.add('d-none');
    
    // 按時間分組
    const grouped = groupByDate(filteredNotifications);
    
    let html = '';
    for (const [dateLabel, notifications] of Object.entries(grouped)) {
      html += `<div class="gmail-date-group mb-2"><strong class="text-muted px-3" style="font-size: 0.85rem;">${dateLabel}</strong></div>`;
      
      notifications.forEach(notif => {
        const isSelected = selectedNotifications.has(notif.msg_ID);
        const isUnread = !notif.is_read;
        const timeStr = formatTime(notif.msg_created_d);
        const preview = truncateText(notif.msg_content || '', 100);
        
        html += `
          <div class="gmail-notification-item ${isUnread ? 'unread' : ''} ${isSelected ? 'selected' : ''}" 
               data-msg-id="${notif.msg_ID}">
            <div class="gmail-notification-checkbox">
              <input type="checkbox" class="form-check-input notification-checkbox" 
                     data-msg-id="${notif.msg_ID}" ${isSelected ? 'checked' : ''}>
            </div>
            <div class="gmail-notification-content" onclick="handleNotificationClick(${notif.msg_ID})">
              <div class="gmail-notification-header">
                <h6 class="gmail-notification-title">${escapeHtml(notif.msg_title || '無標題')}</h6>
                <span class="gmail-notification-time">${timeStr}</span>
              </div>
              <div class="gmail-notification-preview">${escapeHtml(preview)}</div>
              ${notif.urls && Array.isArray(notif.urls) && notif.urls.length > 0 ? notif.urls.map((url, idx) => {
                if (url.type === 'link') {
                  const label = url.label || '查看';
                  // 使用 encodeURIComponent 來正確轉義 URL，然後在 HTML 屬性中使用
                  const safeUrl = url.url.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                  // 直接跳轉到 msg_url 中的 URL
                  return `<a href="${safeUrl}" target="_blank" class="schedule-view-link mt-2" data-msg-id="${notif.msg_ID}" style="text-decoration: underline; color: #0d6efd; cursor: pointer; display: inline-block;" onclick="event.stopPropagation();">${escapeHtml(label)} <i class="fa-solid fa-external-link-alt"></i></a>`;
                }
                return '';
              }).join('') : ''}
            </div>
            <div class="gmail-notification-actions">
              <button class="btn-mark-read" onclick="event.stopPropagation(); toggleReadStatus(${notif.msg_ID})" 
                      title="${isUnread ? '標記為已讀' : '標記為未讀'}">
                <i class="bi ${isUnread ? 'bi-envelope-open' : 'bi-envelope'}"></i>
              </button>
              <button class="btn-delete" onclick="event.stopPropagation(); deleteNotification(${notif.msg_ID})" 
                      title="刪除">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </div>
        `;
      });
    }
    
    notificationList.innerHTML = html;
    
    
    // 綁定複選框事件
    document.querySelectorAll('.notification-checkbox').forEach(checkbox => {
      checkbox.addEventListener('change', function(e) {
        e.stopPropagation();
        const msgId = parseInt(this.dataset.msgId);
        if (this.checked) {
          selectedNotifications.add(msgId);
        } else {
          selectedNotifications.delete(msgId);
        }
        updateSelectionUI();
      });
    });
  }
  
  // 按日期分組
  function groupByDate(notifications) {
    const groups = {};
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    notifications.forEach(notif => {
      const date = new Date(notif.msg_created_d);
      const dateStr = date.toISOString().split('T')[0];
      const dateTime = new Date(dateStr);
      dateTime.setHours(0, 0, 0, 0);
      
      let label;
      const diffTime = today - dateTime;
      const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
      
      if (diffDays === 0) {
        label = '今天';
      } else if (diffDays === 1) {
        label = '昨天';
      } else if (diffDays < 7) {
        label = `${diffDays} 天前`;
      } else {
        label = date.toLocaleDateString('zh-TW', { year: 'numeric', month: 'long', day: 'numeric' });
      }
      
      if (!groups[label]) {
        groups[label] = [];
      }
      groups[label].push(notif);
    });
    
    return groups;
  }
  
  // 格式化時間
  function formatTime(dateStr) {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) {
      return '剛剛';
    } else if (diffMins < 60) {
      return `${diffMins} 分鐘前`;
    } else if (diffHours < 24) {
      return `${diffHours} 小時前`;
    } else if (diffDays < 7) {
      return `${diffDays} 天前`;
    } else {
      return date.toLocaleDateString('zh-TW', { month: 'short', day: 'numeric' });
    }
  }
  
  // 截斷文字
  function truncateText(text, maxLength) {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength) + '...';
  }
  
  // 轉義 HTML
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  // 當前查看的通知 ID
  let currentDetailMsgId = null;
  
  // 處理通知點擊 - 顯示 Gmail 風格詳情視圖，打開時標記為已讀
  window.handleNotificationClick = async function(msgId) {
    const notif = allNotifications.find(n => n.msg_ID === msgId);
    if (!notif) return;
    
    currentDetailMsgId = msgId;
    
    // 隱藏通知列表，顯示詳情視圖
    document.querySelector('.gmail-notifications').style.display = 'none';
    document.getElementById('gmailDetailView').style.display = 'flex';
    
    // 填充詳情內容
    document.getElementById('detailTitleText').textContent = notif.msg_title || '無標題';
    document.getElementById('detailSenderText').textContent = notif.sender_name || '系統';
    
    // 格式化時間
    const timeStr = formatDateTime(notif.msg_created_d);
    document.getElementById('detailTimeText').textContent = timeStr;
    
    // 填充內容
    document.getElementById('detailBodyText').textContent = notif.msg_content || '無內容';
    
    // 更新標記按鈕圖標
    const markReadBtn = document.getElementById('detailMarkReadBtn');
    if (notif.is_read) {
      markReadBtn.innerHTML = '<i class="bi bi-envelope"></i>';
      markReadBtn.title = '標記為未讀';
    } else {
      markReadBtn.innerHTML = '<i class="bi bi-envelope-open"></i>';
      markReadBtn.title = '標記為已讀';
    }
    
    // 如果通知是未讀的，標記為已讀
    if (!notif.is_read) {
      try {
        const formData = new FormData();
        formData.append('msg_ID', msgId);
        
        const response = await fetch('api.php?do=mark_notification_read', {
          method: 'POST',
          body: formData
        });
        
        const data = await response.json();
        if (data.ok) {
          // 更新本地狀態
          notif.is_read = 1;
          updateUnreadCount();
          // 更新按鈕圖標
          markReadBtn.innerHTML = '<i class="bi bi-envelope"></i>';
          markReadBtn.title = '標記為未讀';
          // 重新渲染通知列表以更新樣式
          applyFilter();
        }
      } catch (error) {
        console.error('標記為已讀失敗:', error);
      }
    }
  };
  
  // 關閉詳情視圖
  window.closeDetailView = function() {
    document.getElementById('gmailDetailView').style.display = 'none';
    document.querySelector('.gmail-notifications').style.display = 'block';
    currentDetailMsgId = null;
  };
  
  // 切換詳情通知的已讀/未讀狀態
  window.toggleDetailReadStatus = async function() {
    if (!currentDetailMsgId) return;
    await toggleReadStatus(currentDetailMsgId);
    // 更新按鈕圖標
    const notif = allNotifications.find(n => n.msg_ID === currentDetailMsgId);
    if (notif) {
      const markReadBtn = document.getElementById('detailMarkReadBtn');
      if (notif.is_read) {
        markReadBtn.innerHTML = '<i class="bi bi-envelope"></i>';
        markReadBtn.title = '標記為未讀';
      } else {
        markReadBtn.innerHTML = '<i class="bi bi-envelope-open"></i>';
        markReadBtn.title = '標記為已讀';
      }
    }
  };
  
  // 刪除詳情通知
  window.deleteDetailNotification = async function() {
    if (!currentDetailMsgId) return;
    await deleteNotification(currentDetailMsgId);
    // 刪除後關閉詳情視圖
    closeDetailView();
  };
  
  // 格式化完整日期時間
  function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleString('zh-TW', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    });
  }
  
  // 切換已讀/未讀狀態
  window.toggleReadStatus = async function(msgId) {
    const notif = allNotifications.find(n => n.msg_ID === msgId);
    if (!notif) return;
    
    const isRead = notif.is_read;
    const endpoint = isRead ? 'mark_notification_unread' : 'mark_notification_read';
    
    try {
      const formData = new FormData();
      formData.append('msg_ID', msgId);
      
      const response = await fetch(`api.php?do=${endpoint}`, {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.ok) {
        notif.is_read = !isRead;
        updateUnreadCount();
        applyFilter();
        // 使用 Toast 顯示成功訊息
        Toast.fire({ 
          icon: 'success', 
          title: isRead ? '已標記為未讀' : '已標記為已讀'
        });
      } else {
        Toast.fire({ 
          icon: 'error', 
          title: '操作失敗',
          text: data.msg || '請稍後再試'
        });
      }
    } catch (error) {
      console.error('切換狀態失敗:', error);
      Toast.fire({ 
        icon: 'error', 
        title: '操作失敗',
        text: '網路錯誤'
      });
    }
  };
  
  // 刪除通知（使用正常的 SweetAlert confirm，確定按鈕在右邊）
  window.deleteNotification = async function(msgId) {
    const result = await Swal.fire({
      title: '確定要刪除這則通知嗎？',
      text: '刪除後無法復原',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#6c757d',
      confirmButtonText: '確定刪除',
      cancelButtonText: '取消',
      reverseButtons: false  // 確保確定按鈕在右邊
    });
    
    if (!result.isConfirmed) {
      return;
    }
    
    try {
      const formData = new FormData();
      formData.append('msg_ID', msgId);
      
      const response = await fetch('api.php?do=delete_notification', {
        method: 'POST',
        body: formData
      });
      
      const data = await response.json();
      
      if (data.ok) {
        // 從列表中移除
        allNotifications = allNotifications.filter(n => n.msg_ID !== msgId);
        selectedNotifications.delete(msgId);
        updateUnreadCount();
        applyFilter();
        
        // 刪除成功使用 Toast
        Toast.fire({ icon: 'success', title: '已刪除' });
      } else {
        Toast.fire({ 
          icon: 'error', 
          title: '刪除失敗',
          text: data.msg || '請稍後再試'
        });
      }
    } catch (error) {
      console.error('刪除失敗:', error);
      Toast.fire({ 
        icon: 'error', 
        title: '刪除失敗',
        text: '網路錯誤'
      });
    }
  };
  
  // 更新選擇 UI
  function updateSelectionUI() {
    const allSelected = filteredNotifications.length > 0 && 
                       filteredNotifications.every(n => selectedNotifications.has(n.msg_ID));
    selectAllCheckbox.checked = allSelected;
    selectAllCheckbox.indeterminate = !allSelected && 
                                     filteredNotifications.some(n => selectedNotifications.has(n.msg_ID));
    
    // 更新項目樣式
    document.querySelectorAll('.gmail-notification-item').forEach(item => {
      const msgId = parseInt(item.dataset.msgId);
      if (selectedNotifications.has(msgId)) {
        item.classList.add('selected');
      } else {
        item.classList.remove('selected');
      }
    });
    
    // 更新複選框狀態
    document.querySelectorAll('.notification-checkbox').forEach(checkbox => {
      const msgId = parseInt(checkbox.dataset.msgId);
      checkbox.checked = selectedNotifications.has(msgId);
    });
  }
  
  // 全選/取消全選
  selectAllCheckbox.addEventListener('change', function() {
    if (this.checked) {
      filteredNotifications.forEach(n => selectedNotifications.add(n.msg_ID));
    } else {
      filteredNotifications.forEach(n => selectedNotifications.delete(n.msg_ID));
    }
    updateSelectionUI();
  });
  
  // 篩選按鈕
  document.querySelectorAll('[data-filter]').forEach(btn => {
    btn.addEventListener('click', function() {
      currentFilter = this.dataset.filter;
      document.querySelectorAll('[data-filter]').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      selectedNotifications.clear();
      applyFilter();
    });
  });
  
  // 全部標記為已讀（直接執行，不使用確認對話框，成功後用 Toast）
  document.getElementById('markAllReadBtn').addEventListener('click', async function() {
    const unreadNotifications = allNotifications.filter(n => !n.is_read);
    if (unreadNotifications.length === 0) {
      Toast.fire({ icon: 'info', title: '沒有未讀通知' });
      return;
    }
    
    try {
      let successCount = 0;
      for (const notif of unreadNotifications) {
        const formData = new FormData();
        formData.append('msg_ID', notif.msg_ID);
        
        const response = await fetch('api.php?do=mark_notification_read', {
          method: 'POST',
          body: formData
        });
        
        const data = await response.json();
        if (data.ok) {
          notif.is_read = 1;
          successCount++;
        }
      }
      
      updateUnreadCount();
      applyFilter();
      
      // 使用 Toast 顯示成功訊息
      Toast.fire({ 
        icon: 'success', 
        title: `已將 ${successCount} 則通知標記為已讀`
      });
    } catch (error) {
      console.error('批量標記失敗:', error);
      Toast.fire({ 
        icon: 'error', 
        title: '操作失敗',
        text: '網路錯誤'
      });
    }
  });
  
  // 刪除選中的通知（使用正常的 SweetAlert confirm，確定按鈕在右邊）
  document.getElementById('deleteSelectedBtn').addEventListener('click', async function() {
    const selected = Array.from(selectedNotifications);
    if (selected.length === 0) {
      Toast.fire({ icon: 'info', title: '請先選擇通知' });
      return;
    }
    
    const result = await Swal.fire({
      title: `確定要刪除 ${selected.length} 則通知嗎？`,
      text: '刪除後無法復原',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#6c757d',
      confirmButtonText: '確定刪除',
      cancelButtonText: '取消',
      reverseButtons: false  // 確保確定按鈕在右邊
    });
    
    if (!result.isConfirmed) {
      return;
    }
    
    try {
      let successCount = 0;
      for (const msgId of selected) {
        const formData = new FormData();
        formData.append('msg_ID', msgId);
        
        const response = await fetch('api.php?do=delete_notification', {
          method: 'POST',
          body: formData
        });
        
        const data = await response.json();
        if (data.ok) {
          allNotifications = allNotifications.filter(n => n.msg_ID !== msgId);
          successCount++;
        }
      }
      
      selectedNotifications.clear();
      updateUnreadCount();
      applyFilter();
      
      // 刪除成功使用 Toast
      Toast.fire({ 
        icon: 'success', 
        title: `已刪除 ${successCount} 則通知`
      });
    } catch (error) {
      console.error('批量刪除失敗:', error);
      Toast.fire({ 
        icon: 'error', 
        title: '操作失敗',
        text: '網路錯誤'
      });
    }
  });
  
  // 重新整理
  document.getElementById('refreshBtn').addEventListener('click', function() {
    selectedNotifications.clear();
    loadNotifications();
  });
  
  // 初始化
  document.addEventListener('DOMContentLoaded', function() {
    // 設置默認篩選為全部
    document.getElementById('filterAll').classList.add('active');
    loadNotifications();
  });
  
  // 如果頁面已經載入，立即執行
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadNotifications);
  } else {
    loadNotifications();
  }
  
  // 下載時程表PDF（使用隱藏的iframe，完全不顯示窗口）
  window.downloadSchedulePDF = function(url) {
    console.log('downloadSchedulePDF 被調用', url);
    try {
      // 創建一個完全隱藏的iframe來觸發下載
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
      
      console.log('創建隱藏 iframe，URL:', url);
      
      // 添加到頁面
      document.body.appendChild(iframe);
      
      // 監聽 iframe 載入完成
      iframe.onload = function() {
        console.log('iframe 載入完成，PDF 應該正在生成');
      };
      
      iframe.onerror = function() {
        console.error('iframe 載入錯誤');
        // 如果 iframe 失敗，使用備選方案
        if (document.body.contains(iframe)) {
          document.body.removeChild(iframe);
        }
        // 使用臨時連結作為備選方案
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        setTimeout(function() {
          if (document.body.contains(link)) {
            document.body.removeChild(link);
          }
        }, 100);
      };
      
      // 下載完成後移除iframe（延遲確保下載已開始）
      setTimeout(function() {
        if (document.body.contains(iframe)) {
          document.body.removeChild(iframe);
          console.log('iframe 已移除');
        }
      }, 10000); // 10秒後移除iframe，確保PDF已下載
    } catch (error) {
      console.error('下載失敗:', error);
      // 如果所有方法都失敗，使用新窗口打開（作為最後的備選方案）
      window.open(url, '_blank');
    }
  };
})();
</script>
