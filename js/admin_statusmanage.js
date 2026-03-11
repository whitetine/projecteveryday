// js/admin_statusmanage.js

// 之後這裡可以再加：載入狀態列表的 API
async function loadStatusData() {
    const data = await fetchJson('api.php?do=get_status_list');
    return data;
}

// render 列表（先放假資料給你看位置）
function renderStatusList(statuses) {
    const $list = $('#statusList');
    if (!statuses || statuses.length === 0) {
        $list.html('<div class="p-3 text-muted small">目前尚無狀態設定。</div>');
        return;
    }

    let html = '';
    statuses.forEach((s, idx) => {
        html += `
        <div class="status-row d-flex align-items-center px-3 py-2 border-bottom" data-id="${s.status_ID}">
          <div class="me-3 handle text-muted">
            <i class="fa-solid fa-grip-vertical"></i>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center">
              <span class="badge bg-primary me-2">#${idx + 1}</span>
              <span class="fw-bold me-2">${s.status_name}</span>
              <span class="badge bg-success-subtle text-success">${s.category_label || ''}</span>
            </div>
            <div class="small text-muted mt-1">
              代碼：${s.status_code || '-'} ・ 於篩選顯示：${s.show_in_filter ? '是' : '否'} ・ 儀表板顯示：${s.show_in_dashboard ? '是' : '否'}
            </div>
          </div>
          <div class="me-3">
            <span class="status-color-dot status-color-${s.badge_color || 'primary'}"></span>
          </div>
          <div class="me-3">
            <span class="badge ${s.enabled ? 'bg-outline-success' : 'bg-outline-secondary'}">
              ${s.enabled ? '啟用中' : '已停用'}
            </span>
          </div>
          <div>
            <button class="btn btn-sm btn-outline-secondary me-1 btn-status-edit" data-id="${s.status_ID}">
              <i class="fa-solid fa-pen-to-square"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger btn-status-toggle" data-id="${s.status_ID}">
              <i class="fa-solid fa-power-off"></i>
            </button>
          </div>
        </div>
        `;
    });

    $list.html(html);
}

async function initStatusPage() {
    try {
        const data = await loadStatusData();
        renderStatusList(data.statuses || []);
        initStatusEventHandlers();
    } catch (e) {
        console.error(e);
        $('#statusList').html('<div class="p-3 text-danger">載入狀態資料失敗</div>');
    }
}

function initStatusEventHandlers() {

    // 🔸 新增狀態按鈕
    $('#btnStatusCreate').off('click').on('click', async function () {
        if (!window.Swal || typeof Swal.fire !== 'function') {
            alert('SweetAlert 尚未載入');
            return;
        }

        // 從 template 拿出 HTML
        const tpl = document.getElementById('statusEditTemplate');
        const html = tpl ? tpl.innerHTML : '';

        const { value: formValues } = await Swal.fire({
            title: '新增狀態',
            width: 700,
            showCancelButton: true,
            confirmButtonText: '儲存',
            cancelButtonText: '取消',
            html: html,
            focusConfirm: false,
            preConfirm: () => {
                const name = $('#sw_status_name').val().trim();
                if (!name) {
                    Swal.showValidationMessage('狀態名稱為必填');
                    return false;
                }
                return {
                    status_name: name,
                    status_code: $('#sw_status_code').val().trim(),
                    category: $('#sw_status_category').val(),
                    badge_color: $('#sw_badge_color').val(),
                    enabled: $('#sw_enabled').is(':checked') ? 1 : 0,
                    show_in_filter: $('#sw_show_filter').is(':checked') ? 1 : 0,
                    show_in_dashboard: $('#sw_show_dashboard').is(':checked') ? 1 : 0,
                    is_default: $('#sw_is_default').is(':checked') ? 1 : 0
                };
            }
        });

        if (!formValues) return;

        const res = await fetchJson('api.php?do=create_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formValues)
        });

        if (!res.ok) {
            throw new Error(res.msg || '儲存失敗');
        }

        await Swal.fire({ icon: 'success', title: '儲存成功' });
        initStatusPage(); // 重載列表
    });

    // 之後這裡可以再加：編輯、停用、拖曳排序等事件
}

// 提供給 main.js / SPA 呼叫
if (typeof window !== 'undefined') {
    window.renderStatusManagePage = function () {
        initStatusPage();
    };
}
