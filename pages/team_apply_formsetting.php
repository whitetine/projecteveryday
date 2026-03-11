<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/pdo.php';

// 你可以在這裡做權限檢查（例如科辦 role_ID）
$role_ID = $_SESSION['role_ID'] ?? 0;
// if ($role_ID != 2) { echo "無權限"; exit; }  // 自己改

$cohort_ID = $_SESSION['cohort_ID'] ?? null; // 若你有「目前屆別」概念
$asset_prefix = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false) ? '../' : '';
?>
<link rel="stylesheet" href="<?= $asset_prefix ?>css/team_table_unified.css?v=<?= time() ?>">
<style>
    /* 強制 modal 蓋過 sidebar / navbar */
    .modal {
        z-index: 2000 !important;
    }

    .modal-backdrop {
        z-index: 1990 !important;
    }

    #layoutSidenav_nav {
        z-index: 1020;
        /* 比 modal 低 */
    }
</style>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="mb-0">
            <i class="fa-solid fa-sliders"></i> 申請表欄位設定
        </h3>
        <button class="btn btn-primary" id="btnAddField">
            <i class="fa-solid fa-plus"></i> 新增欄位
        </button>
    </div>

    <div class="alert alert-secondary py-2">
        <div class="small mb-0">
            這裡設定「專題指導申請表」的欄位結構：顯示/必填/排序/選項。<br>
            <span class="text-muted">提示：select / multiselect 的選項請填 JSON：[{ "value":"1","label":"選項A" }]</span>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive team-unified-table-wrap">
                <table class="table align-middle team-unified-table" id="fieldsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:90px;">排序</th>
                            <th style="width:160px;">欄位代碼</th>
                            <th>欄位名稱</th>
                            <th style="width:130px;">型態</th>
                            <th style="width:90px;">顯示</th>
                            <th style="width:90px;">必填</th>
                            <th style="width:180px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="7" class="text-muted">載入中...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 編輯/新增 Modal -->
<div class="modal fade" id="fieldModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fieldModalTitle">欄位設定</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- ✅ 使用者版：不顯示 field_key / 不顯示 JSON -->
                <input type="hidden" id="field_ID">
                <input type="hidden" id="field_key"> <!-- 系統自動生成用 -->

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">題目名稱 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="field_label" placeholder="例如：專題名稱">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">題型 <span class="text-danger">*</span></label>
                        <select class="form-select" id="field_type">
                            <option value="text">單行文字</option>
                            <option value="textarea">多行文字</option>
                            <option value="number">數字</option>
                            <option value="date">日期</option>
                            <option value="select">下拉選單（單選）</option>
                            <option value="multiselect">多選清單</option>
                            <option value="file">上傳檔案</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">顯示</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_enabled" checked>
                            <label class="form-check-label" for="is_enabled">在表單中顯示</label>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">必填</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_required">
                            <label class="form-check-label" for="is_required">必須填寫</label>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">排序</label>
                        <input type="number" class="form-control" id="field_order" value="0">
                    </div>

                    <div class="col-12">
                        <label class="form-label">提示文字（可不填）</label>
                        <input type="text" class="form-control" id="placeholder" placeholder="例如：請輸入專題名稱">
                    </div>

                    <!-- ✅ 只有選單類題型才顯示 -->
                    <div class="col-12" id="optBox" style="display:none;">
                        <label class="form-label">選項（每行一個）</label>
                        <textarea class="form-control" id="options_lines" rows="6"
                            placeholder="例如：&#10;王老師&#10;李老師&#10;張老師"></textarea>
                        <div class="form-text">系統會自動轉成選項，不需要輸入 JSON。</div>
                    </div>

                    <!-- ✅ 進階先藏：你想要「流水號判斷」的規則，建議第二階段再加 -->
                </div>

                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                    <button class="btn btn-primary" id="btnSaveField"><i class="fa-solid fa-floppy-disk"></i> 儲存</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const modalEl = document.getElementById('fieldModal');
            if (!modalEl) return;

            // ✅ 斷開 SPA 容器的 stacking context：直接掛到 body
            if (modalEl.parentElement !== document.body) {
                document.body.appendChild(modalEl);
            }

            // ✅ 確保 modal 顯示時永遠在最上層（比 navbar/offcanvas 都高）
            const style = document.createElement('style');
            style.textContent = `
    .modal { z-index: 10050 !important; }
    .modal-backdrop { z-index: 10040 !important; }
  `;
            document.head.appendChild(style);
        })();

        (function() {
            const API = 'api.php';
            const cohort_ID = <?= $cohort_ID === null ? 'null' : (int)$cohort_ID ?>;

            // 你如果有全站 toast，可改用你自己的；這裡用 Bootstrap toast / alert 的簡化版
            function toast(msg, type = 'success') {
                // 最簡化：右下角 alert（你可改 SweetAlert toast）
                const box = document.createElement('div');
                box.className = `alert alert-${type} position-fixed`;
                box.style.right = '16px';
                box.style.bottom = '16px';
                box.style.zIndex = 3000;
                box.textContent = msg;
                document.body.appendChild(box);
                setTimeout(() => box.remove(), 1600);
            }

            async function apiGet(url) {
                const res = await fetch(url, {
                    credentials: 'same-origin'
                });
                return res.json();
            }
            async function apiPost(doName, payload) {
                const res = await fetch(`${API}?do=${encodeURIComponent(doName)}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json; charset=utf-8'
                    },
                    body: JSON.stringify(payload || {})
                });
                return res.json();
            }

            function renderRows(rows) {
                const tbody = document.querySelector('#fieldsTable tbody');
                if (!rows.length) {
                    tbody.innerHTML = `<tr><td colspan="7" class="text-muted">尚無欄位</td></tr>`;
                    return;
                }
                tbody.innerHTML = rows.map(r => `
      <tr data-id="${r.field_ID}">
        <td>
          <input type="number" class="form-control form-control-sm inpOrder" value="${r.field_order}">
        </td>
        <td class="text-muted">${escapeHtml(r.field_key)}</td>
        <td>${escapeHtml(r.field_label)}</td>
        <td><span class="badge text-bg-secondary">${escapeHtml(r.field_type)}</span></td>
        <td>
          <button class="btn btn-sm ${r.is_enabled==1?'btn-success':'btn-outline-secondary'} btnToggleEnabled">
            ${r.is_enabled==1?'顯示':'隱藏'}
          </button>
        </td>
        <td>
          <button class="btn btn-sm ${r.is_required==1?'btn-warning':'btn-outline-secondary'} btnToggleRequired">
            ${r.is_required==1?'必填':'非必填'}
          </button>
        </td>
        <td class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary btnEdit"><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-sm btn-outline-danger btnDelete"><i class="fa-solid fa-trash"></i></button>
          <button class="btn btn-sm btn-outline-dark btnSaveOrder"><i class="fa-solid fa-sort"></i> 排序</button>
        </td>
      </tr>
    `).join('');
            }

            function escapeHtml(s) {
                return (s ?? '').toString()
                    .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;').replaceAll('"', '&quot;')
                    .replaceAll("'", "&#039;");
            }

            async function loadFields() {
                const url = `${API}?do=get_teamapply_fields${cohort_ID===null?'':`&cohort_ID=${encodeURIComponent(cohort_ID)}`}`;
                const j = await apiGet(url);
                if (!j.ok) {
                    renderRows([]);
                    toast(j.msg || '載入失敗', 'danger');
                    return;
                }
                renderRows(j.data);
            }

            function openModal(row) {
                const modalEl = document.getElementById('fieldModal');
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

                document.getElementById('field_ID').value = row?.field_ID || '';
                document.getElementById('field_key').value = row?.field_key || '';
                document.getElementById('field_label').value = row?.field_label || '';
                document.getElementById('field_type').value = row?.field_type || 'text';
                document.getElementById('field_order').value = row?.field_order ?? 0;
                document.getElementById('is_enabled').value = (row?.is_enabled ?? 1).toString();
                document.getElementById('is_required').value = (row?.is_required ?? 0).toString();
                document.getElementById('placeholder').value = row?.placeholder || '';
                document.getElementById('help_text').value = row?.help_text || '';
                document.getElementById('options_json').value = row?.options_json || '';
                document.getElementById('rules_json').value = row?.rules_json || '';

                document.getElementById('field_key').disabled = !!row?.field_ID; // 建議建立後不改 key
                document.getElementById('fieldModalTitle').textContent = row?.field_ID ? '編輯欄位' : '新增欄位';
                modal.show();
            }

            function safeJsonOrNull(txt) {
                const t = (txt ?? '').trim();
                if (!t) return null;
                JSON.parse(t); // 不回傳值，只驗證
                return t;
            }

            function slugifyKey(label) {
                // 超簡單版：英文就取英文；中文就給固定前綴 + 時間戳（避免重複）
                const s = (label || '').trim().toLowerCase();
                const only = s.replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
                if (only) return only;
                return 'f_' + Date.now(); // 中文題目：用時間戳保證唯一
            }

            function linesToOptionsJson(linesText) {
                const lines = (linesText || '')
                    .split('\n')
                    .map(x => x.trim())
                    .filter(Boolean);

                if (!lines.length) return null;

                const arr = lines.map((label, idx) => ({
                    value: String(idx + 1), // ✅ 用流水號當 value（你要的「流水號判斷」）
                    label
                }));
                return JSON.stringify(arr);
            }

            function toggleOptionBox() {
                const t = document.getElementById('field_type').value;
                document.getElementById('optBox').style.display =
                    (t === 'select' || t === 'multiselect') ? '' : 'none';
            }
            document.getElementById('field_type').addEventListener('change', toggleOptionBox);
            toggleOptionBox();

            async function saveFieldUserUI() {
                const field_ID = document.getElementById('field_ID').value || null;
                const label = document.getElementById('field_label').value.trim();
                const type = document.getElementById('field_type').value;

                if (!label) return toast('題目名稱不能空', 'danger');

                // ✅ 系統自動生成 field_key（建立後就不改）
                let key = document.getElementById('field_key').value.trim();
                if (!field_ID && !key) key = slugifyKey(label);

                // ✅ 選項：每行一個 → options_json（value 用 1,2,3...）
                const options_json = (type === 'select' || type === 'multiselect') ?
                    linesToOptionsJson(document.getElementById('options_lines').value) :
                    null;

                const payload = {
                    field_ID,
                    // cohort_ID 你原本怎麼傳就沿用
                    field_key: key,
                    field_label: label,
                    field_type: type,
                    field_order: parseInt(document.getElementById('field_order').value || '0', 10),
                    is_enabled: document.getElementById('is_enabled').checked ? 1 : 0,
                    is_required: document.getElementById('is_required').checked ? 1 : 0,
                    placeholder: document.getElementById('placeholder').value.trim() || null,
                    help_text: null,

                    // ✅ 使用者不碰 JSON，但後端收到的仍然是 options_json
                    options_json,

                    // ✅ 先不做規則（第二階段再加「條件顯示」UI，存 field_ID）
                    rules_json: null
                };

                const j = await apiPost('save_teamapply_field', payload);
                if (!j.ok) return toast(j.msg || '儲存失敗', 'danger');

                bootstrap.Modal.getInstance(document.getElementById('fieldModal')).hide();
                toast('已儲存');
                await loadFields();
            }

            async function toggleEnabled(id) {
                const j = await apiPost('toggle_teamapply_field', {
                    field_ID: id,
                    mode: 'enabled'
                });
                if (!j.ok) return toast(j.msg || '操作失敗', 'danger');
                toast('已更新');
                await loadFields();
            }
            async function toggleRequired(id) {
                const j = await apiPost('toggle_teamapply_field', {
                    field_ID: id,
                    mode: 'required'
                });
                if (!j.ok) return toast(j.msg || '操作失敗', 'danger');
                toast('已更新');
                await loadFields();
            }

            async function deleteField(id) {
                if (!confirm('確定要刪除此欄位？（若已有人填過會禁止刪除）')) return;
                const j = await apiPost('delete_teamapply_field', {
                    field_ID: id
                });
                if (!j.ok) return toast(j.msg || '刪除失敗', 'danger');
                toast('已刪除');
                await loadFields();
            }

            async function saveOrder(id, order) {
                const j = await apiPost('set_teamapply_field_order', {
                    field_ID: id,
                    field_order: order
                });
                if (!j.ok) return toast(j.msg || '更新排序失敗', 'danger');
                toast('已更新排序');
                await loadFields();
            }

            // === 綁事件 ===
            document.getElementById('btnAddField').addEventListener('click', () => openModal(null));
            document.getElementById('btnSaveField').addEventListener('click', saveField);

            document.querySelector('#fieldsTable tbody').addEventListener('click', async (e) => {
                const tr = e.target.closest('tr[data-id]');
                if (!tr) return;
                const id = parseInt(tr.dataset.id, 10);

                if (e.target.closest('.btnToggleEnabled')) return toggleEnabled(id);
                if (e.target.closest('.btnToggleRequired')) return toggleRequired(id);
                if (e.target.closest('.btnDelete')) return deleteField(id);

                if (e.target.closest('.btnSaveOrder')) {
                    const order = parseInt(tr.querySelector('.inpOrder').value || '0', 10);
                    return saveOrder(id, order);
                }

                if (e.target.closest('.btnEdit')) {
                    // 取該列目前資料（簡單做法：從 DOM 讀；正式可另 call API read）
                    const row = {
                        field_ID: id,
                        field_key: tr.children[1].textContent.trim(),
                        field_label: tr.children[2].textContent.trim(),
                        field_type: tr.querySelector('.badge')?.textContent?.trim() || 'text',
                        field_order: parseInt(tr.querySelector('.inpOrder').value || '0', 10),
                        is_enabled: tr.querySelector('.btnToggleEnabled')?.classList.contains('btn-success') ? 1 : 0,
                        is_required: tr.querySelector('.btnToggleRequired')?.classList.contains('btn-warning') ? 1 : 0,
                    };
                    // 讀完整資料（包含 options_json/rules_json）
                    const j = await apiGet(`${API}?do=get_teamapply_field_one&field_ID=${id}`);
                    if (j.ok && j.data) Object.assign(row, j.data);
                    openModal(row);
                }
            });

            // 首次載入
            loadFields();
        })();
    </script>