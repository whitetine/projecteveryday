// 编辑用户页面脚本
(function() {
  'use strict';
  
  // SweetAlert Toast（右下角通知）
  const Toast = Swal.mixin({
    toast: true,
    position: "bottom-end",
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true
  });
  
  // 等待 DOM 加载完成
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  
  function init() {
    const avatarInput = document.getElementById('avatarInput');
    const avatarPreview = document.getElementById('avatarPreview');
    const btnClearAvatar = document.getElementById('btnClearAvatar');
    const clearAvatar = document.getElementById('clear_avatar');
    const btnCancel = document.getElementById('btnCancel');
    const editForm = document.getElementById('editForm');
    const btnSave = document.getElementById('btnSave');
    
    if (!avatarInput || !avatarPreview || !btnClearAvatar || !clearAvatar || !btnCancel || !editForm || !btnSave) {
      console.error('Required elements not found');
      return;
    }
    
    // 頭像預覽
    avatarInput.addEventListener('change', function(e) {
      const file = e.target.files?.[0];
      if (file) {
        // 验证文件大小（5MB）
        if (file.size > 5 * 1024 * 1024) {
          Toast.fire({
            icon: 'warning',
            title: '檔案過大',
            text: '頭貼大小不能超過 5MB'
          });
          e.target.value = '';
          return;
        }
        
        // 验证文件类型
        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!validTypes.includes(file.type)) {
          Toast.fire({
            icon: 'warning',
            title: '格式錯誤',
            text: '只接受 JPG、PNG 或 WebP 格式'
          });
          e.target.value = '';
          return;
        }
        
        avatarPreview.src = URL.createObjectURL(file);
        clearAvatar.value = '0';
      }
    });
    
    // 清除頭貼
    btnClearAvatar.addEventListener('click', function(e) {
      e.preventDefault();
      
      if (window.Swal) {
        Swal.fire({
          title: '確認清除',
          text: '確定要清除頭貼嗎？',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#dc3545',
          cancelButtonColor: '#6c757d',
          confirmButtonText: '確定清除',
          cancelButtonText: '取消',
          reverseButtons: true
        }).then((result) => {
          if (result.isConfirmed) {
            clearAvatar.value = '1';
            avatarInput.value = '';
            avatarPreview.src = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
          }
        });
      } else {
        if (confirm('確定要清除頭貼嗎？')) {
          clearAvatar.value = '1';
          avatarInput.value = '';
          avatarPreview.src = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
        }
      }
    });
    
    // 取消按鈕
    btnCancel.addEventListener('click', function() {
      if (window.Swal) {
        Swal.fire({
          title: '確認取消',
          text: '確定要取消編輯嗎？未儲存的變更將遺失。',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#6c757d',
          cancelButtonColor: '#28a745',
          confirmButtonText: '確定取消',
          cancelButtonText: '繼續編輯',
          reverseButtons: true
        }).then((result) => {
          if (result.isConfirmed) {
            location.hash = '#pages/admin_usermanage.php';
            if (typeof loadSubpage === 'function') {
              loadSubpage('pages/admin_usermanage.php');
            } else {
              $('#content').load('pages/admin_usermanage.php', function() {
                if (typeof initPageScript === 'function') initPageScript();
              });
            }
          }
        });
      } else {
        if (confirm('確定要取消編輯嗎？')) {
          location.hash = '#pages/admin_usermanage.php';
          if (typeof loadSubpage === 'function') {
            loadSubpage('pages/admin_usermanage.php');
          } else {
            $('#content').load('pages/admin_usermanage.php', function() {
              if (typeof initPageScript === 'function') initPageScript();
            });
          }
        }
      }
    });
    
    // 表單提交
    editForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      
      // 基本驗證
      const nameInput = editForm.querySelector('[name="name"]');
      if (!nameInput || !nameInput.value.trim()) {
        Toast.fire({
          icon: 'warning',
          title: '驗證失敗',
          text: '請輸入姓名'
        });
        nameInput?.focus();
        return;
      }
      
      const roleSelect = editForm.querySelector('[name="role_id"]');
      if (!roleSelect || !roleSelect.value) {
        Toast.fire({
          icon: 'warning',
          title: '驗證失敗',
          text: '請選擇角色'
        });
        roleSelect?.focus();
        return;
      }
      
      const statusSelect = editForm.querySelector('[name="status_id"]');
      if (!statusSelect || !statusSelect.value) {
        Toast.fire({
          icon: 'warning',
          title: '驗證失敗',
          text: '請選擇狀態'
        });
        statusSelect?.focus();
        return;
      }
      
      const fd = new FormData(editForm);
      
      try {
        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>儲存中...';
        
        // 動態判斷 API 路徑
        const apiPath = location.pathname.includes('/pages/') ? 'admin_updateuser.php' : 'pages/admin_updateuser.php';
        const res = await fetch(apiPath, { 
          method: 'POST', 
          body: fd 
        });
        
        // 檢查回應是否為 JSON
        const contentType = res.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
          const text = await res.text();
          console.error('非 JSON 回應:', text);
          throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        const json = await res.json();
        
        if (json.ok) {
          Toast.fire({
            icon: 'success',
            title: '更新成功',
            text: json.msg || '資料已成功更新'
          });
          
          // 延遲一下再返回列表頁，讓用戶看到成功訊息
          setTimeout(() => {
            location.hash = '#pages/admin_usermanage.php';
            if (typeof loadSubpage === 'function') {
              loadSubpage('pages/admin_usermanage.php');
            } else {
              $('#content').load('pages/admin_usermanage.php', function() {
                if (typeof initPageScript === 'function') initPageScript();
              });
            }
          }, 500);
        } else {
          throw new Error(json.msg || '更新失敗');
        }
      } catch (err) {
        console.error('Update error:', err);
        Toast.fire({
          icon: 'error',
          title: '更新失敗',
          text: err.message || '請稍後再試'
        });
      } finally {
        btnSave.disabled = false;
        btnSave.innerHTML = '<i class="fa-solid fa-check me-2"></i>完成修改';
      }
    });
  }
})();

