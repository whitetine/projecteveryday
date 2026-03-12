// 載入組別資料的函數
var _teamteacherLoading = false;  // 防止重複並發請求

function loadTeamTeacherData() {
    if (_teamteacherLoading) return;
    _teamteacherLoading = true;

    let apiPath = 'pages/teamteacher_data.php';
    if (location.pathname.includes('/pages/')) {
        apiPath = 'teamteacher_data.php';
    }

    var container = document.getElementById("groupCards");
    if (!container) {
        _teamteacherLoading = false;
        return;
    }

    console.log('正在載入組別資料，API 路徑：', apiPath);

    fetch(apiPath)
        .then(res => {
            console.log('API 回應狀態：', res.status, res.statusText);
            if (!res.ok) {
                return res.text().then(text => {
                    console.error('API 回應內容：', text);
                    throw new Error('網路錯誤：' + res.status + ' - ' + text.substring(0, 200));
                });
            }
            return res.json();
        })
        .then(data => {
            console.log('API 回應資料：', data);
            if (!data.ok) {
                console.error('API 錯誤：', data.error);
                container.innerHTML = '<div class="alert alert-danger">載入資料失敗：' + (data.error || '未知錯誤') + '</div>';
                return;
            }
            if (!data.groups) {
                console.warn('API 回應中沒有 groups 欄位，完整回應：', data);
                container.innerHTML = '<div class="alert alert-warning">資料格式錯誤：缺少 groups 欄位</div>';
                return;
            }
            if (data.groups.length === 0) {
                console.info('沒有找到任何組別');
                container.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-info">
                            <i class="fa-solid fa-info-circle me-2"></i>
                            目前沒有指導任何組別
                        </div>
                    </div>
                `;
                return;
            }
            console.log('找到 ' + data.groups.length + ' 個組別');
            renderGroups(data.groups);
        })
        .catch(error => {
            console.error('載入資料失敗：', error);
            if (container) {
                container.innerHTML = '<div class="alert alert-danger">載入資料失敗：' + (error && error.message ? error.message : '請稍後再試') + '</div>';
            }
        })
        .finally(function() {
            _teamteacherLoading = false;
        });
}

// 支援兩種載入方式：
// 1. DOMContentLoaded（直接訪問頁面時）
// 2. initPageScript（AJAX 載入時）

// 保存原有的 initPageScript（使用 window 屬性避免重複載入時 Identifier 重複宣告）
var _teamteacherBackup = window.initPageScript;

// 支援 AJAX 載入（通過 main.php 的 hash 路由）
window.initPageScript = function(filename) {
    // 先調用原有的 initPageScript（如果存在）
    if (typeof _teamteacherBackup === 'function') {
        try {
            _teamteacherBackup(filename);
        } catch (e) {
            console.warn('調用原有 initPageScript 時出錯:', e);
        }
    }

    // 如果是 teamteacher 頁面，執行初始化（只執行一次，由 loadTeamTeacherData 內的 _teamteacherLoading 防止重複請求）
    if (filename && filename.includes('teamteacher.php')) {
        console.log('initPageScript 調用 teamteacher 頁面');
        if (document.getElementById('groupCards')) {
            loadTeamTeacherData();
        } else {
            var t = setInterval(function() {
                if (document.getElementById('groupCards')) {
                    clearInterval(t);
                    loadTeamTeacherData();
                }
            }, 100);
            // 最多等 5 秒，避免無限等待
            setTimeout(function() { clearInterval(t); }, 5000);
        }
    }
};

// DOMContentLoaded 事件監聽（直接訪問頁面時）
document.addEventListener("DOMContentLoaded", function() {
    if (document.getElementById('groupCards') && !document.getElementById('groupCards').hasAttribute('data-loaded')) {
        loadTeamTeacherData();
    }
});

// 僅在「直接打開」teamteacher 頁面時才在腳本載入時執行（避免在 main.php#hash 下重複觸發）
if (document.readyState !== 'loading') {
    var isDirectTeamteacher = location.pathname.indexOf('teamteacher.php') !== -1 && !location.hash;
    if (isDirectTeamteacher && document.getElementById('groupCards') && !document.getElementById('groupCards').hasAttribute('data-loaded')) {
        setTimeout(loadTeamTeacherData, 100);
    }
}

// 透過 hash 載入時，app.js 會延遲 200ms 觸發 page:loaded；若此時才載入本腳本，用此事件補觸發一次
document.addEventListener('page:loaded', function (e) {
    var path = (e && e.detail && e.detail.path) ? e.detail.path : '';
    if (path.indexOf('teamteacher.php') !== -1 && document.getElementById('groupCards')) {
        loadTeamTeacherData();
    }
});

// -------------------- 渲染小組卡片 --------------------
function renderGroups(groups) {
    const container = document.getElementById("groupCards");
    container.innerHTML = "";

    if (!groups || groups.length === 0) {
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fa-solid fa-info-circle me-2"></i>
                    目前沒有指導任何組別
                </div>
            </div>
        `;
        return;
    }

    groups.forEach(g => {
        // 渲染成員列表
        let membersHtml = '';
        if (g.members && g.members.length > 0) {
            membersHtml = g.members.map(member => {
                const profileText = member.u_profile ? (member.u_profile.length > 15 ? member.u_profile.substring(0, 15) + '...' : member.u_profile) : '他甚麼也沒留下...';
                const hasAvatar = member.u_img && member.u_img.trim() !== '';
                const defaultAvatarUrl = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
                let avatarUrl = defaultAvatarUrl;
                if (hasAvatar) {
                    const cleanImgName = member.u_img.trim().replace(/\s+/g, '%20');
                    avatarUrl = `headshot/${cleanImgName}`;
                }
                return `
                    <div class="team-member-card">
                        <div class="member-avatar">
                            <img src="${avatarUrl}" alt="${escapeHtml(member.u_name)}" onerror="this.src='${defaultAvatarUrl}'">
                        </div>
                        <div class="member-info">
                            <div class="member-name">${escapeHtml(member.u_name)}</div>
                            <div class="member-details">
                                <div class="member-detail-item">
                                    <i class="fa-solid fa-id-card"></i>
                                    <span>學號 : ${member.u_ID}</span>
                                </div>
                                <div class="member-detail-item">
                                    <i class="fa-solid fa-user"></i>
                                    <span>自介: ${escapeHtml(profileText)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            membersHtml = '<div class="text-muted text-center py-2">暫無成員資料</div>';
        }

        container.innerHTML += `
            <div class="team-group-wrapper">
                <div class="team-header">
                    <i class="fa-solid fa-users me-2"></i>
                    <h5 class="fw-bold mb-3 team-name">${escapeHtml(g.name)}</h5>
                </div>
                <div class="team-members-container">
                    ${membersHtml}
                </div>
            </div>
        `;
    });
}

// HTML 轉義函數
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// -------------------- 渲染最新動態 --------------------
function renderActions(actions) {
    const box = document.getElementById("latestActions");
    // 如果元素不存在（已被註解），直接返回
    if (!box) {
        return;
    }
    box.innerHTML = "";

    if (actions && actions.length > 0) {
        actions.forEach(a => {
            box.innerHTML += `
                <div class="p-2 mb-2 rounded bg-light border">${a}</div>
            `;
        });
    }
}

// -------------------- 渲染里程碑圖表 --------------------
function renderMilestoneChart(groups) {
    if (!groups || groups.length === 0) {
        return;
    }

    const groupNames = groups.map(g => g.name);
    const done = groups.map(g => g.milestone_done);
    const total = groups.map(g => g.milestone_total);
    const rate = done.map((d, i) => total[i] > 0 ? Math.round((d / total[i]) * 100) : 0);

    const ctx = document.getElementById('milestoneChart');
    if (!ctx) {
        return;
    }
    
    const chartCtx = ctx.getContext('2d');

    new Chart(chartCtx, {
        type: 'bar',
        data: {
            labels: groupNames,
            datasets: [{
                label: '里程碑達成率 (%)',
                data: rate,
                borderWidth: 1,
                backgroundColor: '#4e73df'
            }]
        },
        options: {
            indexAxis: 'y',
            scales: {
                x: {
                    min: 0,
                    max: 100,
                    ticks: {
                        callback: v => v + "%"
                    }
                }
            }
        }
    });
}
