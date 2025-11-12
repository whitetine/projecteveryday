// 帳號管理頁面腳本
function initPageScript() {
    // 更新選中數量
    function updateSelectedCount() {
        const checked = $('.user-checkbox:checked').length;
        $('#selectedCount').text(checked);
        $('#batchEditBtn').prop('disabled', checked === 0);
    }

    // 全選
    $('#selectAllBtn').off('click').on('click', function() {
        $('.user-checkbox').prop('checked', true);
        updateSelectedCount();
    });

    // 取消全選
    $('#deselectAllBtn').off('click').on('click', function() {
        $('.user-checkbox').prop('checked', false);
        updateSelectedCount();
    });

    // 批量編輯
    $('#batchEditBtn').off('click').on('click', function() {
        const selected = $('.user-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (selected.length === 0) {
            if (window.Swal) {
                Swal.fire({
                    title: '提示',
                    text: '請至少選擇一個使用者',
                    icon: 'warning',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#ffc107'
                });
            }
            return;
        }

        // 跳轉到批量編輯頁面
        const userIds = selected.join(',');
        if (typeof loadSubpage === 'function') {
            loadSubpage(`pages/admin_batchedituser.php?u_IDs=${userIds}`);
        } else {
            location.href = `#pages/admin_batchedituser.php?u_IDs=${userIds}`;
        }
    });

    // 監聽複選框變化
    $(document).off('change', '.user-checkbox').on('change', '.user-checkbox', function() {
        updateSelectedCount();
    });

    // 點擊用戶卡片切換選中狀態（排除按鈕和連結）
    $(document).off('click', '.user-card').on('click', '.user-card', function(e) {
        // 如果點擊的是按鈕、連結或複選框本身，不處理
        if ($(e.target).closest('.btn, .ajax-link, .user-checkbox, .form-check-label').length > 0) {
            return;
        }
        
        const userId = $(this).data('user-id');
        const checkbox = $(this).find('.user-checkbox');
        if (checkbox.length) {
            checkbox.prop('checked', !checkbox.prop('checked'));
            checkbox.trigger('change');
        }
    });

    // 初始化選中數量
    updateSelectedCount();

    // 切換使用者狀態
    $(document).off("click", ".toggle-btn").on("click", ".toggle-btn", function () {
        const btn = $(this);
        const acc = btn.data('acc');
        const status = btn.data('status');
        const action = btn.data('action');

        if (window.Swal) {
            Swal.fire({
                title: '確認操作',
                text: `確定要${action}此帳號嗎？`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '確定',
                cancelButtonText: '取消',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
                }
            });
        } else {
            if (confirm(`確定要${action}此帳號嗎？`)) {
                location.href = `pages/somefunction/toggle_user.php?acc=${acc}&status=${status}`;
            }
        }
    });

    // 篩選表單提交（使用 AJAX 避免頁面刷新）
    $('#filterForm').off('submit').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const params = new URLSearchParams(new FormData(this));
        // 移除 page 參數（如果有）
        params.delete('page');
        const queryString = params.toString();
        const newPath = `pages/admin_usermanage.php${queryString ? '?' + queryString : ''}`;
        
        // 更新 hash 並觸發頁面重新載入
        location.hash = '#' + newPath;
        
        // 如果 loadSubpage 函數存在，使用它；否則使用 location.reload
        if (typeof loadSubpage === 'function') {
            loadSubpage(newPath);
        } else {
            // 延遲一下確保 hash 更新
            setTimeout(() => {
                window.location.reload();
            }, 100);
        }
    });
}

// 页面加载时初始化
if (typeof initPageScript === 'function') {
    initPageScript();
} else {
    // 如果 initPageScript 还没定义，等待 DOM 加载完成
    $(document).ready(function() {
        if (typeof initPageScript === 'function') {
            initPageScript();
        }
    });
}

