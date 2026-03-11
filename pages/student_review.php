<?php
session_start();
if (!isset($_SESSION['u_ID'])) {
  echo "<script>alert('請先登入');location.href='login.php';</script>";
  exit;
}
?>
<link rel="stylesheet" href="css/student_review.css">

<div class="container-fluid p-4">
  <h3 class="mb-4">學生互評</h3>

    <div class="row g-4">
      <!-- 左側：時段列表 -->
      <div class="col-md-3">
        <div class="card">
          <div class="card-header">
            <h5 class="mb-0">評分時段</h5>
          </div>
          <div class="card-body p-0">
            <div id="periodList" class="period-list">
              <div class="text-center p-3 text-muted">
                <i class="fas fa-spinner fa-spin"></i> 載入中...
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 右側：團隊成員評分 -->
      <div class="col-md-9">
        <div class="card">
          <div class="card-header">
            <h5 class="mb-0" id="rightPanelTitle">請選擇評分時段</h5>
          </div>
          <div class="card-body">
            <div id="teamMembersArea">
              <div class="text-center p-5 text-muted">
                <i class="fas fa-hand-pointer fa-3x mb-3"></i>
                <p>請從左側選擇一個評分時段開始評分</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  // 學生評分頁面 JavaScript - 使用全局函數以便 app.js 調用
  (function() {
    let currentPeriodId = null;
    let currentPeriodStartDate = null; // 時段開始日期
    let currentPeriodEndDate = null; // 時段結束日期
    let currentPeriodType = null; // 時段類型（'in' 或 'cross'）
    let currentRatings = {}; // { u_ID: { score: 1-5, comment: '' } }
    let teamsData = [];
    let membersData = [];
    let ratedRecords = {};
    let isSubmitted = false; // 是否已提交評分

    // 初始化函數 - 供 app.js 調用
    window.initPageScript = function() {
        // 確保 DOM 已載入
        if (document.getElementById('periodList')) {
            loadPeriods();
        } else {
            // 如果元素還沒載入，等待一下
            setTimeout(function() {
                if (document.getElementById('periodList')) {
                    loadPeriods();
                }
            }, 100);
        }
    };

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
            const now = new Date(); // 使用完整的當前時間，包含時分秒
            
            let statusClass = 'secondary';
            let statusText = '未開始';
            
            // 使用完整的日期時間進行比較
            if (now < startDate) {
                statusClass = 'secondary';
                statusText = '未開始';
            } else if (now >= startDate && now <= endDate) {
                statusClass = 'success';
                statusText = '進行中';
            } else if (now > endDate) {
                statusClass = 'secondary';
                statusText = '已結束';
            }
            
            // 學生頁面不需要顯示"啟用"badge，因為學生無權限修改時段狀態
            // 只顯示時段的時間狀態（進行中/已結束/未開始）
            
            // 顯示建立者資訊（如果有）
            const creatorInfo = period.creator_name ? `<small class="text-muted d-block mt-1"><i class="fas fa-user me-1"></i>${escapeHtml(period.creator_name)}</small>` : '';
            
            // 顯示互評類型（團隊內互評或團隊間互評）- 顯示在建立者下方，使用黑色文字
            const periodType = period.period_type || period.pe_mode || '';
            const typeText = periodType === 'cross' ? '團隊間互評' : (periodType === 'in' ? '團隊內互評' : '');
            const typeInfo = typeText ? `<small class="text-muted d-block mt-1"><i class="fas fa-users me-1"></i>${typeText}</small>` : '';
            
            html += `
                <button class="list-group-item list-group-item-action period-item ${currentPeriodId == period.period_ID ? 'active' : ''}" 
                        data-period-id="${period.period_ID}"
                        onclick="selectPeriod(${period.period_ID})">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <h6 class="mb-1">${escapeHtml(period.period_title)}</h6>
                            <small class="text-muted d-block">
                                ${formatDate(period.period_start_d)} ~ ${formatDate(period.period_end_d)}
                            </small>
                            ${creatorInfo}
                            ${typeInfo}
                        </div>
                        <span class="badge bg-${statusClass} ms-2">${statusText}</span>
                    </div>
                </button>
            `;
        });
        
        html += '</div>';
        periodList.innerHTML = html;
    }

    // 選擇時段 - 定義為全局函數以便 onclick 調用
    window.selectPeriod = async function(periodId) {
        currentPeriodId = periodId;
        currentRatings = {};
        isSubmitted = false; // 重置提交狀態
        
        // 更新時段列表的活動狀態
        document.querySelectorAll('.period-item').forEach(item => {
            item.classList.remove('active');
            if (item.dataset.periodId == periodId) {
                item.classList.add('active');
            }
        });
        
        // 載入該時段的團隊成員
        await loadTeamsToReview(periodId);
    };

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
            
            // 保存時段開始和結束日期
            if (data.period) {
                if (data.period.period_start_d) {
                    currentPeriodStartDate = new Date(data.period.period_start_d);
                }
                if (data.period.period_end_d) {
                    currentPeriodEndDate = new Date(data.period.period_end_d);
                }
                // 保存時段類型
                currentPeriodType = data.period.period_type || data.period.pe_mode || null;
            }
            
            // 檢查時段是否未開始（從後端獲取或根據日期時間計算，包含時分秒）
            let isPeriodNotStarted = false;
            if (data.isPeriodNotStarted !== undefined) {
                isPeriodNotStarted = data.isPeriodNotStarted;
            } else if (data.period && data.period.period_start_d) {
                const now = new Date();
                const startDate = new Date(data.period.period_start_d);
                isPeriodNotStarted = now < startDate;
            }
            
            // 檢查時段是否已結束（從後端獲取或根據日期時間計算，包含時分秒）
            let isPeriodEnded = false;
            if (data.isPeriodEnded !== undefined) {
                isPeriodEnded = data.isPeriodEnded;
            } else if (data.period && data.period.period_end_d) {
                const now = new Date();
                const endDate = new Date(data.period.period_end_d);
                isPeriodEnded = now > endDate;
            }
            
            // 如果已結束，確保 currentPeriodEndDate 被正確設置
            if (isPeriodEnded && data.period && data.period.period_end_d) {
                currentPeriodEndDate = new Date(data.period.period_end_d);
            }
            
            // 如果有已評分記錄，載入到 currentRatings，並標記為已提交
            if (Object.keys(ratedRecords).length > 0) {
                isSubmitted = true;
                Object.keys(ratedRecords).forEach(u_ID => {
                    currentRatings[u_ID] = {
                        score: ratedRecords[u_ID].score,
                        comment: ratedRecords[u_ID].comment || ''
                    };
                });
            } else {
                isSubmitted = false;
            }
            
            // 初始化所有成員的評分為0（如果還沒有評分）
            membersData.forEach(member => {
                if (!currentRatings[member.u_ID]) {
                    currentRatings[member.u_ID] = { score: 0, comment: '' };
                }
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
            html += `<h3 class="mb-4">${escapeHtml(teamsData[0].team_project_name)}</h3>`;
        }
        
        // 渲染每個團隊的成員
        Object.values(membersByTeam).forEach(team => {
            if (teamsData.length > 1) {
                html += `<h3 class="mb-4 mt-4">${escapeHtml(team.team_project_name)}</h3>`;
            }
            
            team.members.forEach(member => {
                const rating = currentRatings[member.u_ID] || { score: 0, comment: '' };
                html += renderMemberCard(member, rating);
            });
        });
        
        // 檢查時段是否未開始（使用完整的日期時間，包含時分秒）
        let isPeriodNotStarted = false;
        if (currentPeriodStartDate) {
            const now = new Date();
            const startDate = new Date(currentPeriodStartDate);
            // 添加容錯時間（1秒），避免因為時間精度問題導致無法評分
            isPeriodNotStarted = (now.getTime() - startDate.getTime()) < -1000;
        }
        
        // 檢查時段是否已結束（使用完整的日期時間，包含時分秒）
        let isPeriodEnded = false;
        if (currentPeriodEndDate) {
            const now = new Date();
            const endDate = new Date(currentPeriodEndDate);
            isPeriodEnded = now > endDate;
        }
        
        // 根據是否已提交、未開始或已結束顯示不同的按鈕狀態
        if (isSubmitted) {
            html += `
                <div class="mt-4 text-end">
                    <button class="btn btn-secondary btn-lg" disabled>
                        <i class="fas fa-check"></i> 已提交（無法修改）
                    </button>
                </div>
            `;
        } else if (isPeriodNotStarted) {
            html += `
                <div class="mt-4 text-end">
                    <button class="btn btn-secondary btn-lg" disabled>
                        <i class="fas fa-clock"></i> 時段尚未開始（無法評分）
                    </button>
                </div>
            `;
        } else if (isPeriodEnded) {
            html += `
                <div class="mt-4 text-end">
                    <button class="btn btn-secondary btn-lg" disabled>
                        <i class="fas fa-clock"></i> 時段已結束（無法評分）
                    </button>
                </div>
            `;
        } else {
            html += `
                <div class="mt-4 text-end">
                    <button class="btn btn-primary btn-lg" onclick="submitRatings()">
                        <i class="fas fa-check"></i> 提交評分
                    </button>
                </div>
            `;
        }
        
        teamMembersArea.innerHTML = html;
        
        // 更新右側標題（只顯示互評類型）
        const rightPanelTitle = document.getElementById('rightPanelTitle');
        if (teamsData.length > 0) {
            let titleText = '';
            
            // 只顯示互評類型
            if (currentPeriodType) {
                titleText = currentPeriodType === 'cross' ? '團隊間互評' : 
                           (currentPeriodType === 'in' ? '團隊內互評' : '團隊成員評分');
            } else {
                titleText = '團隊成員評分';
            }
            
            rightPanelTitle.textContent = titleText;
        }
    }

    // 渲染成員卡片
    function renderMemberCard(member, rating) {
        // 檢查時段是否已開始（使用完整的日期時間，包含時分秒）
        let isPeriodNotStarted = false;
        if (currentPeriodStartDate) {
            const now = new Date();
            const startDate = new Date(currentPeriodStartDate);
            // 添加容錯時間（1秒），避免因為時間精度問題導致無法評分
            isPeriodNotStarted = (now.getTime() - startDate.getTime()) < -1000;
        }
        
        // 檢查時段是否已結束（使用完整的日期時間，包含時分秒）
        let isPeriodEnded = false;
        if (currentPeriodEndDate) {
            const now = new Date();
            const endDate = new Date(currentPeriodEndDate);
            isPeriodEnded = now > endDate;
        }
        
        const isReadOnly = isSubmitted || isPeriodEnded || isPeriodNotStarted; // 如果已提交、已結束或未開始，設為只讀
        const stars = renderStars(member.u_ID, rating.score, isReadOnly);
        
        return `
            <div class="member-card mb-3 p-3 border rounded">
                <div class="flex-grow-1">
                    <h4 class="mb-3">${escapeHtml(member.u_name)}</h4>
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
                                  placeholder="請輸入評論（選填）"
                                  ${isReadOnly ? 'readonly disabled' : ''}
                                  style="${isReadOnly ? 'background-color: #e9ecef; cursor: not-allowed;' : ''}">${escapeHtml(rating.comment)}</textarea>
                    </div>
                </div>
            </div>
        `;
    }

    // 渲染星星評分
    function renderStars(userId, currentScore, readonly = false) {
        let html = '<div class="stars">';
        for (let i = 1; i <= 5; i++) {
            const filled = i <= currentScore;
            const clickHandler = readonly ? '' : `onclick="setRating('${userId}', ${i})"`;
            const cursorStyle = readonly ? 'cursor: not-allowed;' : 'cursor: pointer;';
            const opacity = readonly ? '0.5' : '1';
            html += `
                <i class="fas fa-star star ${filled ? 'filled' : ''}" 
                   data-score="${i}" 
                   data-user-id="${userId}"
                   ${clickHandler}
                   style="${cursorStyle} color: ${filled ? '#ffc107' : '#ddd'}; font-size: 1.5rem; margin-right: 0.2rem; opacity: ${opacity}; pointer-events: ${readonly ? 'none' : 'auto'};"></i>
            `;
        }
        html += '</div>';
        return html;
    }

    // 設置評分
    window.setRating = function(userId, score) {
        if (isSubmitted) {
            return; // 已提交，不允許修改
        }
        
        // 檢查時段是否已開始（使用完整的日期時間，包含時分秒）
        if (currentPeriodStartDate) {
            const now = new Date();
            // 確保正確解析日期時間（處理時區問題）
            const startDate = new Date(currentPeriodStartDate);
            
            // 添加一些容錯時間（1秒），避免因為時間精度問題導致無法評分
            const timeDiff = now.getTime() - startDate.getTime();
            if (timeDiff < -1000) { // 如果現在時間比開始時間早超過1秒
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: '錯誤',
                        text: '此評分時段尚未開始，無法進行評分',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#3085d6'
                    });
                }
                return; // 未開始，不允許修改
            }
        }
        
        // 檢查時段是否已結束（使用完整的日期時間，包含時分秒）
        if (currentPeriodEndDate) {
            const now = new Date();
            const endDate = new Date(currentPeriodEndDate);
            
            if (now > endDate) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: '錯誤',
                        text: '此評分時段已結束，無法再進行評分',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#3085d6'
                    });
                }
                return; // 已結束，不允許修改
            }
        }
        
        if (!currentRatings[userId]) {
            currentRatings[userId] = { score: 0, comment: '' };
        }
        
        currentRatings[userId].score = score;
        
        // 更新星星顯示
        const starContainer = document.querySelector(`.star-rating[data-user-id="${userId}"]`);
        if (starContainer) {
            starContainer.innerHTML = renderStars(userId, score, isSubmitted);
        }
    };

    // 提交評分
    window.submitRatings = async function() {
        if (isSubmitted) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '錯誤',
                    text: '您已經提交過評分，無法再次提交',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#3085d6'
                });
            } else {
                alert('您已經提交過評分，無法再次提交');
            }
            return;
        }
        
        if (!currentPeriodId) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '錯誤',
                    text: '請先選擇評分時段',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#3085d6'
                });
            } else {
                alert('請先選擇評分時段');
            }
            return;
        }
        
        // 檢查時段是否已開始（使用完整的日期時間，包含時分秒）
        if (currentPeriodStartDate) {
            const now = new Date();
            // 確保正確解析日期時間（處理時區問題）
            const startDate = new Date(currentPeriodStartDate);
            
            // 添加一些容錯時間（1秒），避免因為時間精度問題導致無法評分
            const timeDiff = now.getTime() - startDate.getTime();
            if (timeDiff < -1000) { // 如果現在時間比開始時間早超過1秒
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: '錯誤',
                        text: '此評分時段尚未開始，無法進行評分',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    alert('此評分時段尚未開始，無法進行評分');
                }
                return;
            }
        }
        
        // 檢查時段是否已結束（使用完整的日期時間，包含時分秒）
        if (currentPeriodEndDate) {
            const now = new Date();
            const endDate = new Date(currentPeriodEndDate);
            
            if (now > endDate) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: '錯誤',
                        text: '此評分時段已結束，無法再進行評分',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    alert('此評分時段已結束，無法再進行評分');
                }
                return;
            }
        }
        
        // 收集所有成員的評分和評論（每個人都要有評分，score可以是0）
        const ratings = {};
        
        // 先從 currentRatings 收集所有評分
        Object.keys(currentRatings).forEach(userId => {
            const rating = currentRatings[userId];
            ratings[userId] = {
                score: rating.score || 0, // 如果沒有選星星，score就是0
                comment: rating.comment || ''
            };
        });
        
        // 收集評論框中的內容
        document.querySelectorAll('.comment-input').forEach(textarea => {
            const userId = textarea.dataset.userId;
            if (ratings[userId]) {
                ratings[userId].comment = textarea.value.trim();
            }
        });
        
        // 確保所有成員都有評分記錄
        membersData.forEach(member => {
            if (!ratings[member.u_ID]) {
                ratings[member.u_ID] = {
                    score: 0,
                    comment: ''
                };
            }
        });
        
        // 驗證：確保所有成員都有評分（score可以是0，但必須有記錄）
        const missingRatings = [];
        membersData.forEach(member => {
            if (!ratings[member.u_ID] || ratings[member.u_ID].score === undefined) {
                missingRatings.push(member.u_name);
            }
        });
        
        if (missingRatings.length > 0) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '錯誤',
                    text: `請為所有成員評分：${missingRatings.join('、')}`,
                    confirmButtonText: '確定',
                    confirmButtonColor: '#3085d6'
                });
            } else {
                alert(`請為所有成員評分：${missingRatings.join('、')}`);
            }
            return;
        }
        
        // 使用 SweetAlert2 確認提交
        if (window.Swal) {
            const result = await Swal.fire({
                title: '確認提交評分',
                text: '確定要提交評分嗎？提交後將無法修改。',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '確定提交',
                cancelButtonText: '取消',
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            });
            
            if (!result.isConfirmed) {
                return;
            }
        } else {
            if (!confirm('確定要提交評分嗎？提交後將無法修改。')) {
                return;
            }
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
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: '錯誤',
                        text: '提交失敗：' + result.error,
                        confirmButtonText: '確定',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    alert('提交失敗：' + result.error);
                }
            } else {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: '提交成功',
                        text: '評分提交成功！',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    alert('評分提交成功！');
                }
                
                // 標記為已提交
                isSubmitted = true;
                // 重新載入數據並禁用所有輸入
                await loadTeamsToReview(currentPeriodId);
                // 禁用提交按鈕
                const submitBtn = document.querySelector('button[onclick="submitRatings()"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> 已提交';
                    submitBtn.classList.remove('btn-primary');
                    submitBtn.classList.add('btn-secondary');
                }
            }
        } catch (error) {
            console.error('提交評分失敗:', error);
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '錯誤',
                    text: '提交失敗，請稍後再試',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#3085d6'
                });
            } else {
                alert('提交失敗，請稍後再試');
            }
        }
    };

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

    // 工具函數：格式化日期時間（包含時分）
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}/${month}/${day} ${hours}:${minutes}`;
    }

    // 監聽評論輸入
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('comment-input')) {
            if (isSubmitted) {
                e.target.value = currentRatings[e.target.dataset.userId]?.comment || '';
                return; // 已提交，不允許修改
            }
            const userId = e.target.dataset.userId;
            if (!currentRatings[userId]) {
                currentRatings[userId] = { score: 0, comment: '' };
            }
            currentRatings[userId].comment = e.target.value;
        }
    });

    // 如果頁面已經載入完成，立即執行初始化
    if (document.getElementById('periodList')) {
        window.initPageScript();
    } else {
        // 等待 DOM 載入
        setTimeout(function() {
            if (document.getElementById('periodList')) {
                window.initPageScript();
            }
        }, 200);
    }
  })();
  </script>

