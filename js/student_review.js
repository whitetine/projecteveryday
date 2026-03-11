// 學生評分頁面 JavaScript

let currentPeriodId = null;
let currentRatings = {}; // { u_ID: { score: 1-5, comment: '' } }
let teamsData = [];
let membersData = [];
let ratedRecords = {};

/**
 * 自定義確認對話框（替換 confirm）
 */
function showConfirmDialog(message, title = '確認操作') {
    return new Promise((resolve) => {
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; display: flex; align-items: center; justify-content: center;';
        
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1054;';
        
        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };
        
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                    <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 16px 16px 0 0; border: none; padding: 20px;">
                        <h5 class="modal-title" style="font-weight: 600; font-size: 18px;">
                            <i class="fa-solid fa-question-circle me-2"></i>${escapeHtml(title)}
                        </h5>
                    </div>
                    <div class="modal-body" style="padding: 30px; font-size: 15px; line-height: 1.6; color: #333;">
                        ${escapeHtml(message).replace(/\n/g, '<br>')}
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid #e9ecef; padding: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="btn btn-secondary cancel-btn" style="border-radius: 8px; padding: 10px 24px; font-weight: 600; min-width: 100px;">
                            <i class="fa-solid fa-times me-2"></i>取消
                        </button>
                        <button type="button" class="btn btn-primary confirm-btn" style="border-radius: 8px; padding: 10px 24px; font-weight: 600; min-width: 100px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                            <i class="fa-solid fa-check me-2"></i>確定
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(backdrop);
        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';
        
        requestAnimationFrame(() => {
            backdrop.style.opacity = '1';
            modal.style.opacity = '1';
        });
        
        function closeModal(result) {
            backdrop.style.opacity = '0';
            modal.style.opacity = '0';
            setTimeout(() => {
                if (document.body.contains(modal)) document.body.removeChild(modal);
                if (document.body.contains(backdrop)) document.body.removeChild(backdrop);
                document.body.style.overflow = '';
                resolve(result);
            }, 150);
        }
        
        const confirmBtn = modal.querySelector('.confirm-btn');
        const cancelBtn = modal.querySelector('.cancel-btn');
        confirmBtn.onclick = () => closeModal(true);
        cancelBtn.onclick = () => closeModal(false);
        backdrop.onclick = () => closeModal(false);
        
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                closeModal(false);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    });
}

// 初始化
document.addEventListener('DOMContentLoaded', function() {
    loadPeriods();
});

// 載入所有時段
async function loadPeriods() {
    try {
        const response = await fetch('pages/student_review_data.php?action=get_all_periods');
        const periods = await response.json();
        
        if (Array.isArray(periods) && periods.length > 0) {
            renderPeriodList(periods);
        } else {
            document.getElementById('periodList').innerHTML = `
                <div class="text-center p-3 text-muted">
                    <i class="fas fa-inbox"></i><br>
                    目前沒有評分時段
                </div>
            `;
        }
    } catch (error) {
        console.error('載入時段失敗:', error);
        document.getElementById('periodList').innerHTML = `
            <div class="text-center p-3 text-danger">
                <i class="fas fa-exclamation-triangle"></i><br>
                載入失敗，請重新整理頁面
            </div>
        `;
    }
}

// 渲染時段列表
function renderPeriodList(periods) {
    const periodList = document.getElementById('periodList');
    
    if (periods.length === 0) {
        periodList.innerHTML = `
            <div class="text-center p-3 text-muted">
                <i class="fas fa-inbox"></i><br>
                目前沒有評分時段
            </div>
        `;
        return;
    }

    let html = '<div class="list-group list-group-flush">';
    
    periods.forEach(period => {
        const startDate = new Date(period.period_start_d);
        const endDate = new Date(period.period_end_d);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        let statusClass = 'secondary';
        let statusText = '未開始';
        
        if (startDate <= today && endDate >= today) {
            statusClass = 'success';
            statusText = '進行中';
        } else if (endDate < today) {
            statusClass = 'secondary';
            statusText = '已結束';
        }
        
        const isActive = period.is_active == 1;
        const activeBadge = isActive ? '<span class="badge bg-primary ms-2">啟用</span>' : '';
        
        html += `
            <button class="list-group-item list-group-item-action period-item ${currentPeriodId == period.period_ID ? 'active' : ''}" 
                    data-period-id="${period.period_ID}"
                    onclick="selectPeriod(${period.period_ID})">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="mb-1">${escapeHtml(period.period_title)}</h6>
                        <small class="text-muted">
                            ${formatDate(period.period_start_d)} ~ ${formatDate(period.period_end_d)}
                        </small>
                    </div>
                    <span class="badge bg-${statusClass}">${statusText}</span>
                </div>
                ${activeBadge}
            </button>
        `;
    });
    
    html += '</div>';
    periodList.innerHTML = html;
}

// 選擇時段
async function selectPeriod(periodId) {
    currentPeriodId = periodId;
    currentRatings = {};
    
    // 更新時段列表的活動狀態
    document.querySelectorAll('.period-item').forEach(item => {
        item.classList.remove('active');
        if (item.dataset.periodId == periodId) {
            item.classList.add('active');
        }
    });
    
    // 載入該時段的團隊成員
    await loadTeamsToReview(periodId);
}

// 載入需要評分的團隊成員
async function loadTeamsToReview(periodId) {
    const teamMembersArea = document.getElementById('teamMembersArea');
    teamMembersArea.innerHTML = `
        <div class="text-center p-5">
            <i class="fas fa-spinner fa-spin fa-3x mb-3"></i>
            <p>載入中...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`pages/student_review_data.php?action=get_teams_to_review&period_ID=${periodId}`);
        const data = await response.json();
        
        if (data.error) {
            teamMembersArea.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> ${data.error}
                </div>
            `;
            return;
        }
        
        if (data.message) {
            teamMembersArea.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> ${data.message}
                </div>
            `;
            return;
        }
        
        teamsData = data.teams || [];
        membersData = data.members || [];
        ratedRecords = data.ratedRecords || {};
        
        // 如果有已評分記錄，載入到 currentRatings
        Object.keys(ratedRecords).forEach(u_ID => {
            currentRatings[u_ID] = {
                score: ratedRecords[u_ID].score,
                comment: ratedRecords[u_ID].comment || ''
            };
        });
        
        renderTeamMembers();
        
    } catch (error) {
        console.error('載入團隊成員失敗:', error);
        teamMembersArea.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 載入失敗，請重新整理頁面
            </div>
        `;
    }
}

// 渲染團隊成員
function renderTeamMembers() {
    const teamMembersArea = document.getElementById('teamMembersArea');
    
    if (membersData.length === 0) {
        teamMembersArea.innerHTML = `
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 目前沒有需要評分的團隊成員
            </div>
        `;
        return;
    }
    
    // 按團隊分組
    const membersByTeam = {};
    membersData.forEach(member => {
        const teamId = member.team_ID;
        if (!membersByTeam[teamId]) {
            membersByTeam[teamId] = {
                team_ID: teamId,
                team_project_name: member.team_project_name,
                members: []
            };
        }
        membersByTeam[teamId].members.push(member);
    });
    
    let html = '';
    
    // 如果有單一團隊，顯示團隊名稱
    if (teamsData.length === 1) {
        html += `<h5 class="mb-3">${escapeHtml(teamsData[0].team_project_name)}</h5>`;
    }
    
    // 渲染每個團隊的成員
    Object.values(membersByTeam).forEach(team => {
        if (teamsData.length > 1) {
            html += `<h5 class="mb-3 mt-4">${escapeHtml(team.team_project_name)}</h5>`;
        }
        
        team.members.forEach(member => {
            const rating = currentRatings[member.u_ID] || { score: 0, comment: '' };
            html += renderMemberCard(member, rating);
        });
    });
    
    html += `
        <div class="mt-4 text-end">
            <button class="btn btn-primary btn-lg" onclick="submitRatings()">
                <i class="fas fa-check"></i> 提交評分
            </button>
        </div>
    `;
    
    teamMembersArea.innerHTML = html;
    
    // 更新右側標題
    if (teamsData.length > 0) {
        document.getElementById('rightPanelTitle').textContent = 
            teamsData.length === 1 ? teamsData[0].team_project_name : '團隊成員評分';
    }
}

// 渲染成員卡片
function renderMemberCard(member, rating) {
    const userImg = member.u_img ? `headshot/${member.u_img}` : 'https://via.placeholder.com/50';
    const stars = renderStars(member.u_ID, rating.score);
    
    return `
        <div class="member-card mb-3 p-3 border rounded">
            <div class="d-flex align-items-start">
                <img src="${userImg}" alt="${escapeHtml(member.u_name)}" 
                     class="rounded-circle me-3" style="width: 50px; height: 50px; object-fit: cover;">
                <div class="flex-grow-1">
                    <h6 class="mb-2">${escapeHtml(member.u_name)}</h6>
                    <div class="mb-2">
                        <label class="form-label small">評分：</label>
                        <div class="star-rating" data-user-id="${member.u_ID}">
                            ${stars}
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">評論：</label>
                        <textarea class="form-control form-control-sm comment-input" 
                                  data-user-id="${member.u_ID}"
                                  rows="2" 
                                  placeholder="請輸入評論（選填）">${escapeHtml(rating.comment)}</textarea>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// 渲染星星評分
function renderStars(userId, currentScore) {
    let html = '<div class="stars">';
    for (let i = 1; i <= 5; i++) {
        const filled = i <= currentScore;
        html += `
            <i class="fas fa-star star ${filled ? 'filled' : ''}" 
               data-score="${i}" 
               data-user-id="${userId}"
               onclick="setRating('${userId}', ${i})"
               style="cursor: pointer; color: ${filled ? '#ffc107' : '#ddd'}; font-size: 1.5rem; margin-right: 0.2rem;"></i>
        `;
    }
    html += '</div>';
    return html;
}

// 設置評分
function setRating(userId, score) {
    if (!currentRatings[userId]) {
        currentRatings[userId] = { score: 0, comment: '' };
    }
    
    currentRatings[userId].score = score;
    
    // 更新星星顯示
    const starContainer = document.querySelector(`.star-rating[data-user-id="${userId}"]`);
    if (starContainer) {
        starContainer.innerHTML = renderStars(userId, score);
    }
}

// 提交評分
async function submitRatings() {
    if (!currentPeriodId) {
        alert('請先選擇評分時段');
        return;
    }
    
    // 收集所有評分和評論
    const ratings = {};
    let hasRating = false;
    
    Object.keys(currentRatings).forEach(userId => {
        const rating = currentRatings[userId];
        if (rating.score > 0) {
            ratings[userId] = {
                score: rating.score,
                comment: rating.comment || ''
            };
            hasRating = true;
        }
    });
    
    // 收集評論框中的內容
    document.querySelectorAll('.comment-input').forEach(textarea => {
        const userId = textarea.dataset.userId;
        if (!ratings[userId] && textarea.value.trim()) {
            // 如果有評論但沒有評分，需要提醒
            if (!currentRatings[userId] || currentRatings[userId].score === 0) {
                alert(`請為 ${getMemberName(userId)} 評分`);
                return;
            }
        }
        if (ratings[userId]) {
            ratings[userId].comment = textarea.value.trim();
        }
    });
    
    if (!hasRating) {
        alert('請至少為一位成員評分');
        return;
    }
    
    // 確認提交
    const confirmed = await showConfirmDialog('確定要提交評分嗎？提交後將無法修改。', '確認提交');
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('pages/student_review_data.php?action=submit_rating', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                period_ID: currentPeriodId,
                ratings: ratings
            })
        });
        
        const result = await response.json();
        
        if (result.error) {
            alert('提交失敗：' + result.error);
        } else {
            alert('評分提交成功！');
            // 重新載入數據
            await loadTeamsToReview(currentPeriodId);
        }
    } catch (error) {
        console.error('提交評分失敗:', error);
        alert('提交失敗，請稍後再試');
    }
}

// 獲取成員名稱
function getMemberName(userId) {
    const member = membersData.find(m => m.u_ID === userId);
    return member ? member.u_name : userId;
}

// 工具函數：轉義 HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 工具函數：格式化日期
function formatDate(dateString) {
    const date = new Date(dateString);
    return `${date.getFullYear()}/${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')}`;
}

// 監聽評論輸入
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('comment-input')) {
        const userId = e.target.dataset.userId;
        if (!currentRatings[userId]) {
            currentRatings[userId] = { score: 0, comment: '' };
        }
        currentRatings[userId].comment = e.target.value;
    }
});

