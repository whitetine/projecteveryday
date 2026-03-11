function loadData(forcedPid = null) {
    const routePid = (() => {
        let pid = "";
        const hash = window.location.hash || '';
        const hashQuery = hash.split('?')[1];
        if (hashQuery) {
            const hashParams = new URLSearchParams(hashQuery);
            pid = hashParams.get("period_ID") || "";
        }
        if (!pid) {
            const params = new URLSearchParams(window.location.search);
            pid = params.get("period_ID") || "";
        }
        return pid;
    })();

    let pid = forcedPid !== null ? forcedPid : routePid;
    let hasSelection = false;
    if (pid) {
        hasSelection = true;
    } else {
        pid = "";
    }

    fetch(`pages/teacher_review_status_data.php?period_ID=${pid}`)
        .then(r => {
            console.log('HTTP 狀態:', r.status, r.statusText);
            if (!r.ok) {
                throw new Error(`HTTP error! status: ${r.status}`);
            }
            return r.text(); // 先取得文字，看看實際回應
        })
        .then(text => {
            console.log('原始回應:', text);
            try {
                const data = JSON.parse(text);
                console.log('API 回應:', data);
                if (data.debug) {
                    console.log('調試資訊:', data.debug);
                }
                renderPage(data, {
                    hasSelection,
                    requestedPeriodId: hasSelection ? pid : null
                });
            } catch (e) {
                console.error('JSON 解析失敗:', e);
                console.error('回應內容:', text);
                alert('資料格式錯誤，請查看控制台');
            }
        })
        .catch(err => {
            console.error('載入資料失敗:', err);
            const tbody = document.getElementById("reviewStatusBody");
            if (tbody) {
                tbody.innerHTML = `
                    <tr><td colspan="5" class="text-center text-danger">
                        載入資料失敗：${err.message}<br>
                        <small>請檢查瀏覽器控制台以獲取更多資訊</small>
                    </td></tr>
                `;
            } else {
                alert('載入資料失敗：' + err.message);
            }
        });
}

function renderPage(data, options = {}) {
    const { hasSelection = false, requestedPeriodId = null } = options;
    if (!data.success) {
        console.error('API 返回錯誤:', data);
        const tbody = document.getElementById("reviewStatusBody");
        if (tbody) {
            tbody.innerHTML = `
                <tr><td colspan="5" class="text-center text-danger">
                    ${data.msg || "載入失敗"}
                </td></tr>
            `;
        } else {
            alert(data.msg || "載入失敗");
        }
        return;
    }
    
    console.log('載入的資料:', data);

    const { periods = [], rows = [] } = data;
    let selectedPeriodId = hasSelection ? (requestedPeriodId ?? data.period_ID ?? null) : null;
    let activePeriod = hasSelection ? (data.active ?? null) : null;

    console.log('解析資料 - periods:', periods, 'active:', activePeriod, 'rows:', rows);

    // 檢查必要資料
    if (!periods || periods.length === 0) {
        console.warn('沒有週次資料');
        const tbody = document.getElementById("reviewStatusBody");
        const sel = document.getElementById("periodSelect");
        const periodInfoEl = document.getElementById("periodInfo");
        
        if (sel) {
            sel.innerHTML = '<option value="">尚未建立時段</option>';
        }
        if (periodInfoEl) {
            periodInfoEl.innerText = '';
        }
        if (tbody) {
            const message = data.msg || '尚未建立時段';
            tbody.innerHTML = `
                <tr><td colspan="5" class="text-center text-muted">${message}</td></tr>
            `;
        }
        // 即使沒有 periods，也要顯示空狀態
        return;
    }

    if (hasSelection) {
        if (!activePeriod && periods.length > 0) {
            // 如果沒有 active，使用第一個 period
            activePeriod = periods[0];
            selectedPeriodId = selectedPeriodId ?? activePeriod.period_ID;
            console.warn('沒有選取的週次資料，使用第一個:', activePeriod);
        }
        
        if (!activePeriod) {
            console.error('沒有選取的週次資料且 periods 為空');
            const tbody = document.getElementById("reviewStatusBody");
            if (tbody) {
                tbody.innerHTML = `
                    <tr><td colspan="5" class="text-center text-muted">沒有選取的週次資料</td></tr>
                `;
            }
            return;
        }
    } else {
        activePeriod = null;
    }

    /* --- 週次選單 --- */
    let sel = document.getElementById("periodSelect");
    if (!sel) {
        console.error('找不到週次選單元素');
        return;
    }
    
    sel.innerHTML = `<option value="">請選擇時段</option>`;
    periods.forEach(p => {
        // 格式化日期時間顯示：標題(開始時間-結束時間)
        // 只顯示日期部分，格式：YYYY-MM-DD
        const startDate = p.period_start_d ? p.period_start_d.split(' ')[0] : '';
        const endDate = p.period_end_d ? p.period_end_d.split(' ')[0] : '';
        const displayText = `${p.period_title}(${startDate}-${endDate})`;
        const isSelected = selectedPeriodId
            ? String(p.period_ID) === String(selectedPeriodId)
            : (activePeriod && String(p.period_ID) === String(activePeriod.period_ID));
        sel.innerHTML += `
            <option value="${p.period_ID}" ${isSelected ? "selected" : ""}>
                ${displayText}
            </option>
        `;
    });

    if (selectedPeriodId) {
        sel.value = selectedPeriodId;
    } else {
        sel.value = "";
    }

    sel.onchange = () => {
        const value = sel.value;
        if (value) {
            window.location.hash = `pages/teacher_review_status.php?period_ID=${value}`;
            loadData(value);
        } else {
            window.location.hash = `pages/teacher_review_status.php`;
            renderPage(data, { hasSelection: false, requestedPeriodId: null });
        }
    };

    /* --- 顯示期間 --- */
    const periodInfoEl = document.getElementById("periodInfo");
    if (periodInfoEl) {
        if (activePeriod) {
            periodInfoEl.innerText = `期間：${activePeriod.period_title}（${activePeriod.period_start_d} ~ ${activePeriod.period_end_d}）`;
        } else {
            periodInfoEl.innerText = '';
        }
    }

    /* --- 表格 --- */
    const tbody = document.getElementById("reviewStatusBody");
    if (!tbody) {
        console.error('找不到表格 body 元素');
        return;
    }

    if (!hasSelection) {
        tbody.innerHTML = `
            <tr><td colspan="5" class="text-center text-muted">請選擇時段以查看資料</td></tr>
        `;
        return;
    }
    
    tbody.innerHTML = "";

    if (!rows || rows.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="5" class="text-center text-muted">（你尚未被加入任何組別）</td></tr>
        `;
        return;
    }

    rows.forEach(r => {
        tbody.innerHTML += `
            <tr>
              <td>${r.team_name}</td>
              <td>${r.expected}</td>
              <td>${r.actual}</td>
              <td>${r.is_complete ? "✅ 完成" : "❌ 未完成"}</td>
              <td>
                <a class="btn btn-sm btn-primary ajax-link"
                  href="pages/teacher_review_detail.php?team_ID=${r.team_ID}&period_ID=${selectedPeriodId || ''}">
                  查看結果
                </a>
              </td>
            </tr>
        `;
    });
}

// 立即執行（因為頁面是通過 AJAX 載入的，DOMContentLoaded 不會再次觸發）
// 當腳本被插入到 DOM 時，頁面元素應該已經存在
// 使用 requestAnimationFrame 確保 DOM 已經渲染完成
(function() {
    function init() {
        const periodSelect = document.getElementById("periodSelect");
        if (periodSelect) {
            // 元素存在，執行載入
            loadData();
        } else {
            // 元素還不存在，使用 requestAnimationFrame 等待下一幀
            requestAnimationFrame(init);
        }
    }
    
    // 使用 requestAnimationFrame 確保 DOM 已渲染
    requestAnimationFrame(init);
})();
