/**
 * 學生表單狀態檢查
 * 用於檢查學生是否有未完成的表單（未提交、待審核或退件）
 * 如果有，則導向到表單填寫頁面
 */
(function() {
  'use strict';
  
  // 檢查是否為學生角色（role_ID = 6）
  function isStudentRole() {
    try {
      // 優先從全域變數獲取（如果 main.php 有設定）
      if (window.CURRENT_USER && window.CURRENT_USER.role_ID) {
        return parseInt(window.CURRENT_USER.role_ID) === 6;
      }
      
      // 嘗試從頁面中的隱藏元素獲取角色資訊
      const roleElement = document.querySelector('[data-role-id]');
      if (roleElement) {
        const roleId = parseInt(roleElement.getAttribute('data-role-id'));
        return roleId === 6;
      }
      
      // 如果無法確定角色，為了安全起見，不執行檢查（避免對非學生角色造成干擾）
      return false;
    } catch (e) {
      return false;
    }
  }
  
  // 更新繳交時段區塊顯示錯誤狀態（已廢棄，不再使用）
  function updateDeadlineListError() {
    // 不再干擾繳交時段區塊，由 loadSchedule() 自己處理
    // 避免 student_form_check.js 的錯誤影響繳交時段顯示
  }
  
  // 檢查學生表單狀態的函數
  function checkStudentFormStatus(callback) {
    // 如果不是學生角色，直接跳過檢查
    if (!isStudentRole()) {
      if (callback) callback(false, null);
      return;
    }
    
    // 直接調用 API，API 會自動從 session 獲取 team_ID（僅對學生角色）
    // 如果不是學生或沒有團隊，API 會返回錯誤，我們就跳過檢查
    fetch('api.php?do=get_team_current_form')
      .then(res => {
        // 暫時使用 .text() 來調試
        return res.text().then(text => {
          try {
            const data = JSON.parse(text);
            // 檢查 API 回應格式（可能是 {ok: true, data: ...} 或 {status: 'ok', data: ...}）
            if (data.ok === false || (data.status && data.status !== 'ok')) {
              throw new Error(data.msg || data.message || 'API 錯誤');
            }
            // 轉換格式以兼容現有代碼
            return {
              status: data.ok ? 'ok' : 'error',
              data: data.data || data
            };
          } catch (parseError) {
            console.error('JSON 解析失敗，原始回應:', text);
            console.error('解析錯誤:', parseError);
            throw new Error('API 回傳了非 JSON 內容: ' + text.substring(0, 200));
          }
        });
      })
      .then(data => {
        if (data.status === 'ok' && data.data && data.data.needs_attention) {
          const formInfo = data.data;
          // 如果有未完成的表單，且當前不在表單填寫頁面，則導向
          const currentHash = location.hash || '';
          const isFormFillPage = currentHash.indexOf('student_form_fill.php') !== -1 || 
                                currentHash.indexOf('form_ID=' + formInfo.form_ID) !== -1;
          
          if (!isFormFillPage) {
            let redirectUrl = 'pages/student_form_fill.php?form_ID=' + formInfo.form_ID + '&team_ID=' + formInfo.team_ID;
            if (formInfo.fs_ID) {
              redirectUrl += '&fs_ID=' + formInfo.fs_ID;
            }
            if (callback) callback(true, redirectUrl);
            return;
          }
        }
        if (callback) callback(false, null);
      })
      .catch(err => {
        // ⭐ 只記錄錯誤，不干擾繳交時段區塊（由 loadSchedule() 自己處理）
        console.error('檢查表單狀態時發生錯誤:', err);
        
        // 不再更新繳交時段區塊，避免干擾正常的載入流程
        if (callback) callback(false, null);
      });
  }
  
  // 導出函數供外部調用（用於 page:loaded 事件）
  window.checkStudentFormStatus = checkStudentFormStatus;
  
  // 執行檢查的統一函數
  function executeCheck() {
    // 檢查學生是否有未完成的表單（僅對學生角色）
    if (isStudentRole()) {
      checkStudentFormStatus(function(shouldRedirect, redirectUrl) {
        if (shouldRedirect) {
          location.href = redirectUrl;
          return;
        }
      });
    }
  }
  
  // 監聽 hash 改變
  window.addEventListener('hashchange', function () {
    executeCheck();
  });
  
  // ⭐ 監聽 page:loaded 事件（當頁面通過 AJAX 載入完成時）
  document.addEventListener('page:loaded', function(e) {
    const path = e.detail ? e.detail.path : '';
    if (path && path.includes('project_upload.php')) {
      // 切回 project_upload.php 時重新執行檢查
      setTimeout(executeCheck, 100);
    }
  });
  
  // 同時監聽 jQuery 事件（向後兼容）
  if (typeof $ !== 'undefined') {
    $(document).on('pageLoaded', function(e, path) {
      if (path && path.includes('project_upload.php')) {
        setTimeout(executeCheck, 100);
      }
    });
  }
  
  // 頁面載入時也檢查一次（僅在整頁刷新時）
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      executeCheck();
    });
  } else {
    // 如果 DOM 已經載入完成，立即檢查（僅在整頁刷新時）
    executeCheck();
  }
  
})();

