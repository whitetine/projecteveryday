// 类组管理页面脚本
(function() {
  'use strict';
  
  // 等待 DOM 加载完成
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  
  function init() {
    // 新增類組確認
    if (typeof window.confirmAdd === 'undefined') {
      window.confirmAdd = function() {
        const nameInput = document.getElementById('group_name');
        if (!nameInput) return;
        
        const name = nameInput.value.trim();
        if (!name) {
          if (window.Swal) {
            Swal.fire({
              icon: 'warning',
              title: '請輸入類組名稱',
              confirmButtonText: '好',
              reverseButtons: true
            });
          } else {
            alert('請輸入類組名稱');
          }
          nameInput.focus();
          return;
        }
        
        // 二次確認
        if (window.Swal) {
          Swal.fire({
            title: '確定要新增這個類組？',
            text: `名稱：${name}`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '確定新增',
            cancelButtonText: '取消',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
          }).then(result => {
            if (result.isConfirmed) {
              // 使用 AJAX 提交，避免页面跳转
              const form = document.getElementById('addForm');
              const formData = new FormData(form);
              fetch('api.php?do=add_group', {
                method: 'POST',
                body: formData
              }).then(response => {
                if (response.ok) {
                  // 清空输入框
                  document.getElementById('group_name').value = '';
                  // 重新加载页面内容
                  if (typeof loadSubpage === 'function') {
                    loadSubpage('pages/group_manage.php');
                  } else {
                    location.reload();
                  }
                } else {
                  if (window.Swal) {
                    Swal.fire('錯誤', '新增失敗，請稍後再試', 'error');
                  } else {
                    alert('新增失敗');
                  }
                }
              }).catch(err => {
                console.error(err);
                if (window.Swal) {
                  Swal.fire('錯誤', '無法連線到伺服器', 'error');
                } else {
                  alert('無法連線到伺服器');
                }
              });
            }
          });
        } else {
          if (confirm(`確定要新增類組「${name}」嗎？`)) {
            document.getElementById('addForm').submit();
          }
        }
      };
    }
    
    // 停用/啟用確認
    document.querySelectorAll('.toggle-group-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        const act = btn.textContent.trim().replace(/\s+/g, ' '); // 停用 / 啟用
        const name = this.closest('tr').querySelector('td:nth-child(2)').textContent.trim();
        
        if (window.Swal) {
          Swal.fire({
            title: `確定要${act}「${name}」？`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: `確定${act}`,
            cancelButtonText: '取消',
            confirmButtonColor: act.includes('停用') ? '#dc3545' : '#28a745',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
          }).then(r => {
            if (r.isConfirmed) {
              // 使用 AJAX 提交，避免页面跳转
              const formData = new FormData(this);
              fetch('api.php?do=toggle_group', {
                method: 'POST',
                body: formData
              }).then(response => {
                if (response.ok) {
                  // 重新加载页面内容
                  if (typeof loadSubpage === 'function') {
                    loadSubpage('pages/group_manage.php');
                  } else {
                    location.reload();
                  }
                } else {
                  if (window.Swal) {
                    Swal.fire('錯誤', '操作失敗，請稍後再試', 'error');
                  } else {
                    alert('操作失敗');
                  }
                }
              }).catch(err => {
                console.error(err);
                if (window.Swal) {
                  Swal.fire('錯誤', '無法連線到伺服器', 'error');
                } else {
                  alert('無法連線到伺服器');
                }
              });
            }
          });
        } else {
          if (confirm(`確定要${act}「${name}」嗎？`)) {
            this.submit();
          }
        }
      });
    });
    
    // 顯示 Toast 通知
    function showToastFromHash() {
      const hash = window.location.hash || '';
      const query = hash.split('?')[1];
      if (!query) return;
      
      const p = new URLSearchParams(query);
      const toast = p.get('toast');
      const name = p.get('name') ? decodeURIComponent(p.get('name')) : '';
      if (!toast) return;
      
      let title = '操作完成',
          icon = 'success',
          text = '';
      if (toast === 'enabled') {
        title = '已啟用';
        text = name ? `類組：${name}` : '';
      } else if (toast === 'disabled') {
        title = '已停用';
        text = name ? `類組：${name}` : '';
      } else {
        title = '操作失敗';
        icon = 'error';
      }
      
      if (window.Swal) {
        Swal.fire({
          icon,
          title,
          text,
          timer: 1600,
          showConfirmButton: false,
          toast: true,
          position: 'top-end'
        });
      }
      
      // 清掉 hash 參數，避免重整又跳出
      const base = hash.split('?')[0];
      history.replaceState(null, '', location.pathname + location.search + base);
    }
    
    // 若有全域 initPageScript，放這裡呼叫；否則直接呼叫一次
    if (typeof initPageScript === 'function') {
      const _old = initPageScript;
      window.initPageScript = function() {
        _old();
        showToastFromHash();
      };
    } else {
      showToastFromHash();
    }
  }
})();

