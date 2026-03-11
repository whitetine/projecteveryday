// 立即執行（因為頁面是通過 AJAX 載入的，DOMContentLoaded 不會再次觸發）
// 使用 requestAnimationFrame 確保 DOM 已經渲染完成
var MATRIX_PAGE_SIZE = window.MATRIX_PAGE_SIZE || 6;
window.MATRIX_PAGE_SIZE = MATRIX_PAGE_SIZE;

window.__trd_state = window.__trd_state || {
    currentTeamId: null,
    currentPeriodId: null,
    isLoading: false,
    matrixPage: 0,
    matrixPageSize: MATRIX_PAGE_SIZE,
    latestData: null
};

function getState() {
    return window.__trd_state;
}

function setState(updates) {
    Object.assign(window.__trd_state, updates);
}

function resolveIds(updateGlobal = true) {
    const hash = window.location.hash || '';
    const query = hash.split('?')[1] || '';
    const params = new URLSearchParams(query);
    let teamId = parseInt(params.get('team_ID') || '', 10);
    let periodId = parseInt(params.get('period_ID') || '', 10);

    if (Number.isNaN(teamId)) {
        teamId = window.TEAM_ID || (typeof TEAM_ID !== 'undefined' ? TEAM_ID : 0);
    }
    if (Number.isNaN(periodId)) {
        periodId = window.PERIOD_ID || (typeof PERIOD_ID !== 'undefined' ? PERIOD_ID : 0);
    }

    if (updateGlobal) {
        window.TEAM_ID = teamId;
        window.PERIOD_ID = periodId;
    }

    return { teamId, periodId };
}

(function() {
    function init() {
        setupHashListener();
        setupViewToggle();

        let attempts = 0;
        const MAX_ATTEMPTS = 20;

        function tryLoad() {
            if (syncParamsAndLoad(true)) {
                return;
            }
            if (++attempts <= MAX_ATTEMPTS) {
                setTimeout(tryLoad, 100);
            }
        }

        tryLoad();
    }

    // 檢查元素是否存在
    const checkReady = () => {
        if (document.getElementById('matrix-wrapper')) {
            init();
        } else {
            requestAnimationFrame(checkReady);
        }
    };
    requestAnimationFrame(checkReady);
})();

function setupHashListener(){
    window.addEventListener('hashchange', () => {
        syncParamsAndLoad();
    });
}

function syncParamsAndLoad(force = false) {
    const { teamId, periodId } = resolveIds();

    if (!teamId || teamId <= 0) {
        return false;
    }

    const state = getState();
    if (!force && teamId === state.currentTeamId && periodId === state.currentPeriodId) {
        return false;
    }

    setState({ currentTeamId: teamId, currentPeriodId: periodId });
    
    // 更新「回列表」按鈕連結
    updateBackLink(periodId);
    
    clearDetailPlaceholders();
    loadDetailData(teamId, periodId);
    return true;
}

function updateBackLink(periodId) {
    const backLinkEl = document.getElementById("back-link");
    if (backLinkEl && periodId) {
        backLinkEl.href = `pages/teacher_review_status.php?period_ID=${periodId}`;
    }
}

function clearDetailPlaceholders() {
    const matrixWrapper = document.getElementById("matrix-wrapper");
    const avgWrapper = document.getElementById("avg-table-wrapper");
    const noReviewList = document.getElementById("no-review-list");

    if (matrixWrapper) matrixWrapper.innerHTML = '<div class="text-muted p-4">載入中…</div>';
    if (avgWrapper) avgWrapper.innerHTML = '';
    if (noReviewList) noReviewList.innerHTML = '';
}

  function loadDetailData(teamId, periodId) {
    const tId = teamId || (typeof TEAM_ID !== 'undefined' ? TEAM_ID : 0);
    const pId = periodId || (typeof PERIOD_ID !== 'undefined' ? PERIOD_ID : 0);
    
    fetch(`pages/teacher_review_detail_data.php?team_ID=${tId}&period_ID=${pId}`)
      .then(r => {
        // 先檢查回應狀態
        if (!r.ok) {
          throw new Error(`HTTP error! status: ${r.status}`);
        }
        // 先取得文字內容，檢查是否為 JSON
        return r.text();
      })
      .then(text => {
        // 檢查回應是否為有效的 JSON
        let res;
        try {
          res = JSON.parse(text);
        } catch (e) {
          // 如果不是 JSON，可能是 PHP 錯誤頁面
          console.error('回應不是有效的 JSON:', text.substring(0, 500));
          throw new Error('伺服器回應格式錯誤，請檢查伺服器日誌');
        }
        
        if (!res.success) {
          const errorMsg = document.getElementById('error-message');
          if (errorMsg) {
            errorMsg.textContent = '讀取資料錯誤：' + (res.msg || '未知錯誤');
            errorMsg.style.display = 'block';
          } else {
            alert("讀取資料錯誤：" + res.msg);
          }
          return;
        }
  
        // 隱藏錯誤訊息（如果之前有顯示）
        const errorMsg = document.getElementById('error-message');
        if (errorMsg) {
          errorMsg.style.display = 'none';
        }
  
        setState({ latestData: res, matrixPage: 0 });

        renderBasicInfo(res);
        renderMatrix(res);
        renderAvgTable(res);
        renderNoReview(res);
      })
      .catch(err => {
        const errorMsg = document.getElementById('error-message');
        if (errorMsg) {
          errorMsg.textContent = '載入資料時發生錯誤：' + err.message;
          errorMsg.style.display = 'block';
        } else {
          alert('載入資料時發生錯誤：' + err.message);
        }
      });
  }
  
  function renderBasicInfo(d){
    const teamNameEl = document.getElementById("team-name");
    const periodInfoEl = document.getElementById("period-info");
    const statEl = document.getElementById("stat-badges");
    const backLinkEl = document.getElementById("back-link");

    if (!teamNameEl || !periodInfoEl || !statEl) {
      console.warn("teacher_review_detail: 找不到標題相關元素，略過 renderBasicInfo");
      return;
    }

    teamNameEl.textContent = `組別：${d.teamName}`;
    periodInfoEl.textContent = `期間：${d.periodTitle}（${d.periodRange}）`;

    const stat = `
      <span class="badge bg-secondary me-1">學生數：${d.N}</span>
      <span class="badge bg-info text-dark me-1">
        本週已評分學生數：${countReviewer(d.didReview)}
      </span>
      <span class="badge ${d.completed ? "bg-success" : "bg-danger"}">
        ${d.completed ? "已完成" : "未完成"}
      </span>
    `;
    statEl.innerHTML = stat;

    // 更新「回列表」按鈕連結，包含當前時段 ID
    if (backLinkEl && d.periodId) {
      backLinkEl.href = `pages/teacher_review_status.php?period_ID=${d.periodId}`;
    }
  }
  
  function countReviewer(obj){
    let c = 0;
    Object.values(obj).forEach(v => { if(v>0) c++; });
    return c;
  }
  
  /* ---------- 矩陣 ---------- */
  function getColumnIds(data){
    if (data.targetIds && data.targetIds.length) return data.targetIds;
    return data.studentIds || [];
  }

  function getColumnNames(data){
    if (data.targetStudents && Object.keys(data.targetStudents).length) return data.targetStudents;
    return data.students || {};
  }

  function changeMatrixPage(direction){
    const state = getState();
    const data = state.latestData;
    if (!data) return;
    const colIds = getColumnIds(data);
    const totalPages = Math.max(1, Math.ceil(colIds.length / (state.matrixPageSize || MATRIX_PAGE_SIZE)));
    let newPage = (state.matrixPage || 0) + direction;
    newPage = Math.max(0, Math.min(totalPages - 1, newPage));
    if (newPage !== state.matrixPage) {
      setState({ matrixPage: newPage });
      renderMatrix(data);
    }
  }

  function renderMatrix(d){
    const state = getState();
    const rowIds = d.studentIds || [];
    const rowIdSet = new Set(rowIds);
    const rowNames = d.students || {};
    const colIdsAll = getColumnIds(d);
    const colNames = getColumnNames(d);
    const wrapper = document.getElementById("matrix-wrapper");
    if (!wrapper) {
      console.warn("teacher_review_detail: 找不到 matrix-wrapper，略過 renderMatrix");
      return;
    }

    const pageSize = state.matrixPageSize || MATRIX_PAGE_SIZE;
    const totalPages = Math.max(1, Math.ceil(colIdsAll.length / pageSize));
    let currentPage = Math.min(state.matrixPage || 0, totalPages - 1);
    if (currentPage < 0) currentPage = 0;
    setState({ matrixPage: currentPage });

    const start = currentPage * pageSize;
    const pageColIds = colIdsAll.slice(start, start + pageSize);
    const sameMatrix = rowIds.length === pageColIds.length && rowIds.every((id, idx) => id === pageColIds[idx]);
  
    let html = `
      <table class="table table-bordered table-sm table-matrix sticky-head">
      <thead>
        <tr>
          <th>評分人 \\ 被評人</th>
    `;
  
    pageColIds.forEach(s => {
      html += `<th>${colNames[s] ?? s}<br><small>${s}</small></th>`;
    });
  
    html += `<th>已評數</th></tr></thead><tbody>`;
  
    rowIds.forEach(a => {
      html += `<tr><th class="text-start">${rowNames[a] ?? a}</th>`;
  
      pageColIds.forEach(b => {
        // 判斷是否需要評分
        let shouldShowDash = false;
        const teamMap = d.studentTeamMap || {};
        const isTeamInMode = d.peMode === 'in' || !d.peMode; // 預設為團隊內互評
        const isCrossTeamMode = d.peMode === 'cross';
        
        if (isTeamInMode) {
          // 團隊內互評：自己評自己的顯示 "-"
          if (a === b) {
            shouldShowDash = true;
          }
        } else if (isCrossTeamMode) {
          // 跨團隊互評：同一團隊成員之間不需要評分，顯示 "-"
          const reviewerTeam = teamMap[a];
          const reviewedTeam = teamMap[b];
          if (reviewerTeam && reviewedTeam && reviewerTeam === reviewedTeam) {
            shouldShowDash = true;
          }
        }
        
        if (shouldShowDash) {
          html += `<td class="cell-self">—</td>`;
        } else {
          const sc = ((d.score || {})[a] || {})[b] ?? "";
          const cmRaw = ((d.comment || {})[a] || {})[b];
          const cm = (cmRaw ?? "").toString();
          html += `
            <td>
              <div class="cell-score">${sc}</div>
              <div class="cell-comment">${cm.replace(/\n/g,"<br>")}</div>
            </td>
          `;
        }
      });
  
      html += `<td><strong>${(d.didReview || {})[a] || 0}</strong></td></tr>`;
    });
  
    html += `</tbody></table>`;

    if (totalPages > 1) {
      html += `
        <div class="matrix-pagination">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-matrix-page="prev" ${currentPage === 0 ? "disabled" : ""}>上一頁</button>
          <span>第 ${currentPage + 1} / ${totalPages} 頁</span>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-matrix-page="next" ${currentPage >= totalPages - 1 ? "disabled" : ""}>下一頁</button>
        </div>
      `;
    }
  
    wrapper.innerHTML = html;

    const prevBtn = wrapper.querySelector('[data-matrix-page="prev"]');
    const nextBtn = wrapper.querySelector('[data-matrix-page="next"]');
    if (prevBtn) {
      prevBtn.addEventListener('click', () => changeMatrixPage(-1));
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', () => changeMatrixPage(1));
    }
  }
  
  /* ---------- 平均分 ---------- */
  function renderAvgTable(d){
    const ids = (d.targetIds && d.targetIds.length) ? d.targetIds : (d.studentIds || []);
    const names = (d.targetStudents && Object.keys(d.targetStudents).length) ? d.targetStudents : (d.students || {});
    const wrapper = document.getElementById("avg-table-wrapper");
    if (!wrapper) {
      console.warn("teacher_review_detail: 找不到 avg-table-wrapper，略過 renderAvgTable");
      return;
    }
  
    let html = `<table class="table table-bordered table-sm w-auto">
      <thead><tr><th>學生</th><th>平均分</th><th>被評次數</th></tr></thead>
      <tbody>`;
  
    ids.forEach(s => {
      html += `
        <tr>
          <td>${names[s] ?? s}（${s}）</td>
          <td>${(d.avg || {})[s] ?? "—"}</td>
          <td>${(d.recvCnt || {})[s] ?? 0}</td>
        </tr>
      `;
    });
  
    html += "</tbody></table>";
  
    wrapper.innerHTML = html;
  }
  
  /* ---------- 未完成 ---------- */
  function renderNoReview(d){
    const listEl = document.getElementById("no-review-list");
    if (!listEl) {
      console.warn("teacher_review_detail: 找不到 no-review-list，略過 renderNoReview");
      return;
    }
    let html = "";
    if (d.notReviewed.length === 0) {
      html = '<li class="text-muted">（無）</li>';
    } else {
      d.notReviewed.forEach(id => {
        const label = (d.students && d.students[id]) ? d.students[id] : id;
        html += `<li>${label}</li>`;
      });
    }
    listEl.innerHTML = html;
  }
  
  /* ---------- 分數/評論切換 ---------- */
  function setupViewToggle(){
    const matrixWrapper = document.getElementById("matrix-wrapper");
    const btn  = document.getElementById("toggleView");
    if (!btn || !matrixWrapper) return;
    
    let mode = false;

    btn.addEventListener("click", () => {
      mode = !mode;
      matrixWrapper.classList.toggle("comment-mode", mode);
      btn.textContent = mode ? "顯示分數" : "顯示評論";
    });
  }
  