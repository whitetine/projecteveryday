console.log("submission_view.js loaded");

let __rawRows = [];
const __SHOW_TUTOR_COL__ = window.__SHOW_TUTOR_COL__ !== false;
const __SHOW_TEACHER_COL__ = window.__SHOW_TEACHER_COL__ !== false;
const __SHOW_CLASS_COL__ = window.__SHOW_CLASS_COL__ !== false;
const __TABLE_COLSPAN__ =
  Number(window.__TABLE_COLSPAN__) ||
  (5 + (__SHOW_TEACHER_COL__ ? 1 : 0) + (__SHOW_TUTOR_COL__ ? 1 : 0) + (__SHOW_CLASS_COL__ ? 1 : 0));

// 學生端送出申請後，透過 localStorage 觸發其他分頁（例如 submission_view）重新載入
if (!window.__submissionViewReloadBound) {
  window.__submissionViewReloadBound = true;
  window.addEventListener('storage', function (e) {
    if (!e || e.key !== 'submission_view_reload' || !e.newValue) return;
    let payload;
    try {
      payload = JSON.parse(e.newValue);
    } catch (err) {
      return;
    }
    const select = document.getElementById("itemSelect");
    const tbody = document.getElementById("tableBody");
    if (!select || !tbody) return;
    // 若 payload 有指定 doc_ID，僅在目前選取同一文件時重新載入
    if (payload && payload.doc_ID && String(payload.doc_ID) !== String(select.value)) {
      return;
    }
    // 重新載入目前文件的繳交列表
    loadData();
  });
}

// 從 hash 解析 query（main.php 以 hash 載入時 server 收不到 doc_id）
function getDocIdFromHash() {
  var hash = (window.location.hash || "").slice(1);
  var q = hash.indexOf("?");
  if (q < 0) return null;
  var params = hash.slice(q + 1).split("&");
  for (var i = 0; i < params.length; i++) {
    var p = params[i].split("=");
    if (p[0] === "doc_id" && p[1]) return decodeURIComponent(p[1].replace(/\+/g, " "));
  }
  return null;
}

// 供 app.js / page:loaded 呼叫：一切換到收件狀況頁就載入列表
window.initSubmissionView = function () {
  const select = document.getElementById("itemSelect");
  const tbody = document.getElementById("tableBody");
  if (!select || !tbody) return;

  if (select.dataset.bound === "1") {
    // 已綁定過（同一份 DOM），只重新載入資料（例如從 page:loaded 觸發）
    loadData();
    return;
  }
  select.dataset.bound = "1";

  // URL 帶入的 doc_id 無效時提示（PHP 已將選單切到預設）
  if (window.__DOC_ID_INVALID_MSG__) {
    alert(window.__DOC_ID_INVALID_MSG__);
    window.__DOC_ID_INVALID_MSG__ = "";
  }

  // main.php hash 載入時從 hash 讀取 doc_id 並預選
  var docIdFromHash = getDocIdFromHash();
  if (docIdFromHash) {
    var found = false;
    for (var i = 0; i < select.options.length; i++) {
      if (select.options[i].value === docIdFromHash) {
        select.value = docIdFromHash;
        found = true;
        break;
      }
    }
    if (!found) alert("此文件目前未開放查看繳交狀況");
  }

  select.addEventListener("change", loadData);

  const filterStatus = document.getElementById("filterStatus");
  if (filterStatus) {
    filterStatus.addEventListener("change", applyFilterAndRender);
  }

  // 使用目前「選擇文件」與「篩選狀態」立即載入完整列表（預設值已由 PHP 帶入）
  loadData();
};

// main.php 以 hash 動態載入時：切換到收件狀況頁立即執行載入（不依賴 DOMContentLoaded）
document.addEventListener("page:loaded", function (e) {
  var path = (e.detail && e.detail.path) || "";
  if (path.indexOf("submission_view") === -1) return;
  if (typeof window.initSubmissionView === "function") {
    window.initSubmissionView();
  } else if (typeof loadData === "function") {
    loadData();
  }

  // 再保險一次：若 1 秒後表格仍然沒有任何資料列，但「選擇文件」已有值，則自動再觸發一次載入
  setTimeout(function () {
    try {
      var select = document.getElementById("itemSelect");
      var tbody = document.getElementById("tableBody");
      if (!select || !tbody) return;
      if (!select.value) return;
      if (tbody.querySelectorAll("tr").length === 0) {
        if (typeof loadData === "function") {
          loadData();
        }
      }
    } catch (err) {
      console.error("submission_view fallback load error:", err);
    }
  }, 1000);
});

// 動態解析 API 路徑
function getApiPath(filename) {
  const path = window.location.pathname || '';
  if (path.includes('/pages/')) {
    return filename; // 如果在 /pages/ 目錄下，使用相對路徑
  }
  return `/pages/${filename}`; // 否則使用絕對路徑
}

// 供頁面初始化與 page:loaded 呼叫；使用目前「選擇文件」與「篩選狀態」查詢
async function loadData() {
  const select = document.getElementById("itemSelect");
  const tbody = document.getElementById("tableBody");
  if (!tbody) return;
  const docID = select ? select.value : "";

  if (!docID) {
    tbody.innerHTML = `<tr><td colspan="${__TABLE_COLSPAN__}" class="loading">請選擇文件</td></tr>`;
    __rawRows = [];
    return;
  }

  // 注意：你的 API 檔名是 submission_view_data.php
  const url = `${getApiPath('submission_view_data.php')}?doc_ID=${encodeURIComponent(docID)}`;
  console.log("fetch:", url);

  try {
    const res = await fetch(url, { cache: "no-store" });
    const text = await res.text();

    if (!res.ok) {
      tbody.innerHTML = `<tr><td colspan="${__TABLE_COLSPAN__}" class="loading">API 錯誤 (${res.status})</td></tr>`;
      __rawRows = [];
      return;
    }

    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      console.error("API 回傳不是 JSON：", text);
      tbody.innerHTML = `<tr><td colspan="${__TABLE_COLSPAN__}" class="loading">API 回傳不是 JSON</td></tr>`;
      __rawRows = [];
      return;
    }

    if (data && data.error) {
      tbody.innerHTML = `<tr><td colspan="${__TABLE_COLSPAN__}" class="loading">${esc(data.msg || "發生錯誤")}</td></tr>`;
      __rawRows = [];
      return;
    }

    // 處理新的數據格式：可能是 {data: [...], can_review: true} 或直接是數組
    if (data && typeof data === 'object' && 'data' in data) {
      __rawRows = Array.isArray(data.data) ? data.data : [];
      window.__CAN_REVIEW__ = data.can_review === true;
    } else {
      __rawRows = Array.isArray(data) ? data : [];
      // 如果沒有 can_review 標誌，默認根據角色判斷（後端應該會提供）
      window.__CAN_REVIEW__ = false;
    }
    applyFilterAndRender();

  } catch (err) {
    console.error(err);
    tbody.innerHTML = `<tr><td colspan="${__TABLE_COLSPAN__}" class="loading">載入失敗（看 Console）</td></tr>`;
    __rawRows = [];
  }
}

function applyFilterAndRender() {
  const filterStatus = document.getElementById("filterStatus");
  const filterValue = filterStatus ? filterStatus.value : 'all';

  const filtered = (__rawRows || []).filter(r => {
    const submitted = !!r.is_submitted;

    if (filterValue === 'all') {
      return true; // 顯示全部
    } else if (filterValue === 'submitted') {
      return submitted; // 只顯示已繳交
    } else if (filterValue === 'not_submitted') {
      return !submitted; // 只顯示未繳交
    }

    return true;
  });

  renderRows(filtered);
}

function renderRows(rows) {
  const tbody = document.getElementById("tableBody");

  if (!rows || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="${__TABLE_COLSPAN__}" class="loading">無符合條件的資料</td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(r => `
    <tr>
      <td>${esc(r.file)}</td>
      <td>${esc(r.team)}</td>
      <td>${esc(r.members)}</td>
      ${__SHOW_TEACHER_COL__ ? `<td>${esc(r.teacher)}</td>` : ``}
      ${__SHOW_TUTOR_COL__ ? `<td>${esc(r.tutor)}</td>` : ``}
      ${__SHOW_CLASS_COL__ ? `<td>${esc(r.class)}</td>` : ``}
      <td>${renderReview(r)}</td>
      <td>${esc(r.time)}</td>
      <td>${renderAction(r)}</td>
    </tr>
  `).join("");

  // 綁定按鈕點擊事件
  bindActionButtons();
}

function renderReview(r) {
  // 未繳交 -> 顯示未繳交
  if (!r.is_submitted) {
    return `<span class="badge badge-warn">未繳交</span>`;
  }

  // 已繳交 -> 顯示狀態：待審核、已通過、已退件
  const s = String(r.status || r.review || "").trim();
  if (s === "已通過") return `<span class="badge badge-ok">已通過</span>`;
  if (s === "已退件") return `<span class="badge badge-bad">已退件</span>`;
  return `<span class="badge badge-wait">待審核</span>`;
}

function renderAction(r) {
  const canReview = window.__CAN_REVIEW__ === true || r.can_review === true;

  // 未繳交 -> 顯示提醒按鈕（所有角色都可以提醒）
  if (!r.is_submitted) {
    return `<button class="btn btn-remind" data-team-id="${r.team_ID || ''}" title="提醒">提醒</button>`;
  }

  // 已繳交 -> 根據狀態顯示不同按鈕
  const status = r.dcsub_status !== null && r.dcsub_status !== undefined ? parseInt(r.dcsub_status) : 0;
  const statusText = String(r.status || r.review || "").trim();
  const subID = r.sub_ID || '';
  const url = r.url ? escAttr(r.url) : '';

  // 已通過或已退件 -> 顯示查看
  if (statusText === "已通過" || statusText === "已退件") {
    if (url) {
      return `<button class="btn btn-view" data-sub-id="${subID}" data-url="${url}" title="查看文件">查看</button>`;
    }
    return "-";
  }

  // 待審核 -> 只有召集人可以審核，其他人只能查看
  if (statusText === "待審核" || status === 0) {
    if (url) {
      if (canReview) {
        return `<button class="btn btn-review" data-sub-id="${subID}" data-url="${url}" title="審核">審核</button>`;
      } else {
        return `<button class="btn btn-view" data-sub-id="${subID}" data-url="${url}" title="查看文件">查看</button>`;
      }
    }
    return "-";
  }

  return "-";
}

function esc(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
function escAttr(s) {
  return esc(s).replaceAll("`", "&#096;");
}

// 單一 PDF 預覽視窗（樣式與 apply_preview_list 查看相同，可選擇通過/退件）
function openCompareModal(docID, subID) {
  const modal = document.getElementById('submissionPreviewModal');
  const iframe = document.getElementById('submissionPreviewFrame');
  const titleEl = document.getElementById('submissionPreviewModalLabel');
  const btnApprove = document.getElementById('submissionBtnApprove');
  const btnReject = document.getElementById('submissionBtnReject');
  if (!modal || !iframe || !docID || !subID) return;

  const row = __rawRows.find(function (r) { return r.sub_ID == subID; }) || {};
  const statusText = String(row.status || row.review || '').trim();
  const rejectReason = row.reject_reason || '';

  // 標題：文件名稱 + 團隊名稱
  const docName = row.file || '';
  const teamName = row.team || '';
  if (titleEl) {
    let title = docName || '申請內容預覽';
    if (teamName) title += ' - ' + teamName;
    titleEl.textContent = title;
  }

  // PDF 路徑：使用與 apply_preview_list 相同的 download_document_form_original_pdf.php
  const base = (location.pathname || '').replace(/\/[^/]*$/, '') || '';
  const prefix = base ? base + '/' : '/';
  const urlOriginal =
    prefix +
    'pages/download_document_form_original_pdf.php?doc_ID=' +
    encodeURIComponent(docID) +
    '&submission_id=' +
    encodeURIComponent(subID) +
    '#zoom=70';

  iframe.src = urlOriginal;

  // 依角色決定是否顯示通過/退件按鈕（目前僅科辦與指導老師可審核）
  const roleId = typeof window.__ROLE_ID__ !== 'undefined'
    ? parseInt(window.__ROLE_ID__, 10)
    : null;
  const canReviewHere = (roleId === 2 || roleId === 4) && statusText === '待審核';
  // 只有在「待審核」狀態下才顯示通過 / 退件按鈕，其餘狀態只允許查看
  if (btnApprove) btnApprove.classList.toggle('d-none', !canReviewHere);
  if (btnReject) btnReject.classList.toggle('d-none', !canReviewHere);

  // 記住目前這筆 subID，給按鈕用
  modal.dataset.currentDocId = String(docID);
  modal.dataset.currentSubId = String(subID);

  if (modal && modal.parentElement !== document.body) {
    document.body.appendChild(modal);
    modal.style.zIndex = '10005';
  }

  if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
    bootstrap.Modal.getOrCreateInstance(modal).show();
  } else if (typeof $ !== 'undefined' && $.fn.modal) {
    $(modal).modal('show');
  } else {
    modal.style.display = 'block';
    modal.classList.add('show');
  }

  // 若此筆為「已退件」且有退件備註，於預覽時一併顯示退件原因（呈現方式與審核頁類似）
  if (statusText === '已退件' && rejectReason) {
    if (window.Swal) {
      Swal.fire({
        title: '退件原因',
        text: rejectReason,
        icon: 'info',
        confirmButtonText: '關閉'
      });
    } else {
      alert('退件原因：\n' + rejectReason);
    }
  }
}

// 從收件狀況頁的預覽視窗進行通過 / 退件（實際仍由各自的資料審核頁處理邏輯）
(function setupSubmissionPreviewActions() {
  const btnApprove = document.getElementById('submissionBtnApprove');
  const btnReject = document.getElementById('submissionBtnReject');
  const modal = document.getElementById('submissionPreviewModal');
  const rejectReasonModal = document.getElementById('submissionRejectReasonModal');
  const rejectReasonTextarea = document.getElementById('submissionRejectReasonTextarea');
  const btnRejectReasonConfirm = document.getElementById('btnSubmissionRejectReasonConfirm');

  if (!modal) return;

  // 將退件原因 Modal 也移至 body 並確保其 z-index 更高，足以蓋過預覽 Modal
  if (rejectReasonModal && rejectReasonModal.parentElement !== document.body) {
    document.body.appendChild(rejectReasonModal);
    rejectReasonModal.style.zIndex = '10500';
  }

  const roleId = typeof window.__ROLE_ID__ !== 'undefined'
    ? parseInt(window.__ROLE_ID__, 10)
    : null;
  const OFFICE_ENDPOINT = (location.pathname || '').includes('/pages/')
    ? 'apply_preview_list.php'
    : 'pages/apply_preview_list.php';
  const TEACHER_ENDPOINT = (location.pathname || '').includes('/pages/')
    ? 'apply_preview_teacher_list.php'
    : 'pages/apply_preview_teacher_list.php';

  function handleAction(action) {
    const subId = modal.dataset.currentSubId;
    if (!subId) return;

    // 不要關閉預覽視窗，直接開啟退件原因視窗，顯示在其上方
    if (action === 'reject') {
      if (rejectReasonTextarea) rejectReasonTextarea.value = '';

      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(rejectReasonModal).show();
      } else if (typeof $ !== 'undefined' && $.fn.modal) {
        $(rejectReasonModal).modal('show');
      } else {
        // 簡單 fallback：直接顯示退件視窗
        if (rejectReasonModal) {
          rejectReasonModal.style.display = 'block';
          rejectReasonModal.classList.add('show');
        }
      }
      return;
    }

    let endpoint = null;
    let confirmTitle = '';
    let confirmText = '';
    let confirmIcon = '';

    if (roleId === 2) { // 科辦
      endpoint = OFFICE_ENDPOINT;
      if (action === 'approve') {
        confirmTitle = '確認通過';
        confirmText = '該文件須由指導老師審核是否通過，請確認是否同意讓該組通過。';
        confirmIcon = 'question';
      } else {
        return;
      }
    } else if (roleId === 4) { // 指導老師
      endpoint = TEACHER_ENDPOINT;
      if (action === 'approve') {
        confirmTitle = '確認通過';
        confirmText = '確定將此申請通過？';
        confirmIcon = 'question';
      } else {
        return;
      }
    } else {
      return;
    }

    const doRequest = function (reason) {
      const body =
        'apply_ID=' + encodeURIComponent(subId) +
        '&action=' + encodeURIComponent(action) +
        '&ajax=1' +
        (action === 'reject'
          ? '&reject_reason=' + encodeURIComponent(reason || '')
          : '');

      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
              bootstrap.Modal.getOrCreateInstance(modal).hide();
            } else if (typeof $ !== 'undefined' && $.fn.modal) {
              $(modal).modal('hide');
            } else {
              modal.style.display = 'none';
              modal.classList.remove('show');
            }
            if (window.Swal) {
              Swal.fire({
                icon: 'success',
                title: '成功',
                text: data.status_text || '',
                timer: 1500,
                showConfirmButton: false
              });
            }
            // 重新載入收件狀況列表，反映最新狀態
            if (typeof loadData === 'function') {
              loadData();
            }
          } else {
            if (window.Swal) {
              Swal.fire('失敗', data.msg || '更新失敗', 'error');
            } else {
              alert(data.msg || '更新失敗');
            }
          }
        })
        .catch(function () {
          if (window.Swal) {
            Swal.fire('錯誤', '無法連線', 'error');
          } else {
            alert('無法連線');
          }
        });
    };

    if (window.Swal) {
      Swal.fire({
        title: confirmTitle,
        text: confirmText,
        icon: confirmIcon,
        showCancelButton: true,
        confirmButtonText: '確定',
        cancelButtonText: '取消',
        reverseButtons: true
      }).then(function (r) {
        if (r.isConfirmed) doRequest('');
      });
    } else {
      if (confirm(confirmText)) doRequest('');
    }
  }

  if (btnApprove) {
    btnApprove.addEventListener('click', function () {
      handleAction('approve');
    });
  }
  if (btnReject) {
    btnReject.addEventListener('click', function () {
      handleAction('reject');
    });
  }

  if (btnRejectReasonConfirm) {
    btnRejectReasonConfirm.addEventListener('click', function () {
        const subId = modal.dataset.currentSubId;
        const reason = (rejectReasonTextarea.value || '').trim();
        if (!subId) return;

        const endpoint = (roleId === 2) ? OFFICE_ENDPOINT : ((roleId === 4) ? TEACHER_ENDPOINT : null);
        if (!endpoint) return;

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'apply_ID=' + encodeURIComponent(subId) + '&action=reject&reject_reason=' + encodeURIComponent(reason) + '&ajax=1'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.ok) {
                // 隱藏兩個 Modal
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getInstance(rejectReasonModal).hide();
                    bootstrap.Modal.getInstance(modal).hide();
                } else {
                    $(rejectReasonModal).modal('hide');
                    $(modal).modal('hide');
                }
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: '已退件',
                        text: data.status_text || '',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
                // 重新載入收件狀況列表，反映最新狀態
                if (typeof loadData === 'function') {
                    loadData();
                }
            } else {
                if (window.Swal) {
                    Swal.fire('失敗', data.msg || '更新失敗', 'error');
                } else {
                    alert(data.msg || '更新失敗');
                }
            }
        })
        .catch(function () {
            if (window.Swal) {
                Swal.fire('錯誤', '無法連線', 'error');
            } else {
                alert('無法連線');
            }
        });
    });
  }
})();

function bindActionButtons() {
  document.querySelectorAll('.btn-view').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      const subID = this.dataset.subId;
      const select = document.getElementById("itemSelect");
      const docID = select ? select.value : '';
      if (!docID || !subID) return;
      openCompareModal(docID, subID);
    });
  });

  document.querySelectorAll('.btn-review').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      const subID = this.dataset.subId;
      const select = document.getElementById("itemSelect");
      const docID = select ? select.value : '';
      if (!docID || !subID) return;
      openCompareModal(docID, subID);
    });
  });

  // 提醒按鈕
  document.querySelectorAll('.btn-remind').forEach(btn => {
    btn.addEventListener('click', async function () {
      const teamId = (this.dataset.teamId || '').trim();
      const select = document.getElementById("itemSelect");
      const docID = select ? select.value : '';
      if (!teamId || !docID) {
        if (window.Swal) {
          Swal.fire('提示', '無法取得團隊或文件資訊，請重新載入頁面', 'warning');
        } else {
          alert('無法取得團隊或文件資訊，請重新載入頁面');
        }
        return;
      }
      const originalText = this.textContent;
      this.disabled = true;
      this.textContent = '發送中...';
      const apiPath = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
      const formData = new FormData();
      formData.append('team_ID', teamId);
      formData.append('doc_ID', docID);
      try {
        const res = await fetch(apiPath + '?do=send_submission_remind', {
          method: 'POST',
          body: formData,
          cache: 'no-store'
        });
        const data = await res.json();
        if (data.ok && data.message) {
          if (window.Swal) {
            Swal.fire('提醒已發送', data.message, 'success');
          } else {
            alert(data.message);
          }
        } else {
          const errMsg = data.msg || data.message || '未知錯誤';
          if (window.Swal) {
            Swal.fire('發送失敗', errMsg, 'error');
          } else {
            alert('發送失敗：' + errMsg);
          }
        }
      } catch (err) {
        console.error('提醒發送失敗：', err);
        if (window.Swal) {
          Swal.fire('發送失敗', '請稍後再試', 'error');
        } else {
          alert('發送失敗，請稍後再試');
        }
      } finally {
        this.disabled = false;
        this.textContent = originalText;
      }
    });
  });
}
