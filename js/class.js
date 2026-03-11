// 班導師頁面 JavaScript
// 使用 IIFE 封裝變數，避免與其他頁面衝突
(function() {
  'use strict';
  
  let pieChart = null;
  let selectedGroupId = null;
  let groupsData = []; // 保存類組資料供點擊事件使用
  let statisticsData = null; // 保存統計資料供點擊事件使用

  // 初始化函數（供外部調用）
  window.loadClassPageScript = function() {
    // 確保 Chart.js 已載入
    if (typeof Chart === 'undefined') {
      console.error('Chart.js 尚未載入，請確認 Chart.js 已正確引入');
      return;
    }
    
    // 初始化
    $(document).ready(function() {
      loadStatistics();
    
      // 收合/展開團隊區塊（使用事件委派，確保動態生成的元素也能觸發）
      $(document).off('click', '#teamsHeader').on('click', '#teamsHeader', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $section = $('#teamsSection');
        const $icon = $('#teamsToggleIcon');
        
        if ($section.hasClass('collapsed')) {
          $section.removeClass('collapsed').show();
          $icon.text('-');
        } else {
          $section.addClass('collapsed');
          $icon.text('+');
          // 延遲隱藏，讓動畫效果完成
          setTimeout(function() {
            if ($section.hasClass('collapsed')) {
              $section.hide();
            }
          }, 300);
        }
      });
    });
  };
  
  // 如果 Chart.js 已經載入，立即執行
  if (typeof Chart !== 'undefined') {
    window.loadClassPageScript();
  } else {
    // 等待 Chart.js 載入
    $(document).ready(function() {
      let checkCount = 0;
      const checkInterval = setInterval(function() {
        checkCount++;
        if (typeof Chart !== 'undefined') {
          clearInterval(checkInterval);
          window.loadClassPageScript();
        } else if (checkCount > 50) {
          // 50 次檢查後（約 5 秒）放棄
          clearInterval(checkInterval);
          console.error('Chart.js 載入超時');
        }
      }, 100);
    });
  }

  // 載入統計資料
  function loadStatistics() {
    $.ajax({
      url: 'pages/class_data.php',
      method: 'GET',
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          // 檢查是否沒有班級
          if (response.no_class) {
            showNoClassMessage();
            return;
          }
          
          statisticsData = response; // 保存統計資料
          updateStatistics(response);
          createPieChart(response.groups, response);
        } else {
          showError(response.msg || '載入資料失敗');
        }
      },
      error: function(xhr, status, error) {
        console.error('載入統計資料失敗:', error);
        showError('載入資料時發生錯誤');
      }
    });
  }
  
  // 顯示沒有班級的提示訊息
  function showNoClassMessage() {
    const $statisticsCard = $('.statistics-card');
    $statisticsCard.html(`
      <div class="alert alert-info d-flex align-items-center" style="margin: 0; padding: 2rem;">
        <i class="fa-solid fa-info-circle me-3" style="font-size: 2rem;"></i>
        <div>
          <h5 class="mb-2">班級尚未進行專題</h5>
          <p class="mb-0 text-muted">目前您尚未被指派管理任何班級，或班級尚未開始進行專題活動。</p>
        </div>
      </div>
    `);
    
    // 隱藏團隊區塊
    $('#teamsSection').hide();
  }

  // 更新統計標題
  function updateStatistics(data) {
  const $title = $('#statistics-title');
  
  if (data.all_joined) {
    $title.text('全班皆加入團隊');
  } else {
    $title.text(`尚有${data.unjoined_count}人未加入團隊`);
  }
}

  // 創建圓餅圖
  function createPieChart(groups, data) {
    const ctx = document.getElementById('groupPieChart');
    if (!ctx) return;
    
    // 檢查 Chart.js 是否已載入
    if (typeof Chart === 'undefined') {
      console.error('Chart.js 尚未載入，請確認 Chart.js 已正確引入');
      return;
    }

    // 保存類組資料
    groupsData = groups;

    // 銷毀舊的圖表
    if (pieChart) {
      pieChart.destroy();
    }

    // 準備資料
    const labels = [];
    const values = [];
    const colors = [
      '#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b',
      '#fa709a', '#fee140', '#30cfd0', '#a8edea', '#fed6e3'
    ];

    groups.forEach((group, index) => {
      labels.push(`${group.group_name} ${group.student_count}人`);
      values.push(parseInt(group.student_count));
    });

    // 如果還有未加入的學生，加入「未加入」區塊
    if (data.unjoined_count > 0) {
      labels.push(`未加入 ${data.unjoined_count}人`);
      values.push(data.unjoined_count);
    }

    // 註冊 datalabels 插件（如果已載入）
    if (typeof ChartDataLabels !== 'undefined' && typeof Chart !== 'undefined') {
      Chart.register(ChartDataLabels);
    }

    pieChart = new Chart(ctx, {
    type: 'pie',
    data: {
      labels: labels,
      datasets: [{
        data: values,
        backgroundColor: colors.slice(0, labels.length),
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          display: false // 隱藏下方圖例
        },
        datalabels: typeof ChartDataLabels !== 'undefined' ? {
          color: '#fff',
          font: {
            weight: 'bold',
            size: 14
          },
          formatter: function(value, context) {
            const label = context.chart.data.labels[context.dataIndex];
            return label;
          },
          textAlign: 'center',
          anchor: 'center',
          clamp: false,
          clip: false,
          padding: 6,
          textStrokeColor: 'rgba(0, 0, 0, 0.5)',
          textStrokeWidth: 2
        } : {},
        tooltip: {
          callbacks: {
            label: function(context) {
              const label = context.label || '';
              const value = context.parsed || 0;
              const total = context.dataset.data.reduce((a, b) => a + b, 0);
              const percentage = ((value / total) * 100).toFixed(1);
              return `${label}: ${value}人 (${percentage}%)`;
            }
          }
        }
      },
      onClick: function(event, elements) {
        if (elements.length > 0) {
          const index = elements[0].index;
          // 檢查是否點擊了類組（不是「未加入」）
          if (index < groupsData.length) {
            const groupId = groupsData[index].group_ID;
            const groupName = groupsData[index].group_name;
            loadTeamDetails(groupId, groupName);
          } else {
            // 點擊了「未加入」區塊
            const unjoinedCount = statisticsData ? statisticsData.unjoined_count : data.unjoined_count || 0;
            loadUnjoinedStudents(unjoinedCount);
          }
        }
      }
    }
  });
}

  // 載入團隊詳情
  function loadTeamDetails(groupId, groupName) {
    selectedGroupId = groupId;
    
    // 顯示團隊區塊並確保是展開狀態
    const $section = $('#teamsSection');
    const $icon = $('#teamsToggleIcon');
    $section.show().removeClass('collapsed');
    $icon.text('一');
    $('#selectedGroupName').text(`${groupName} - 團隊列表`);
    
    // 顯示載入中
    $('#teamsList').html('<div class="loading"><i class="fas fa-spinner fa-spin fa-2x"></i><p>載入中...</p></div>');
    
    $.ajax({
      url: 'pages/class_data.php',
      method: 'GET',
      data: {
        group_ID: groupId
      },
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          displayTeams(response.teams);
        } else {
          $('#teamsList').html('<div class="alert alert-danger">載入團隊資料失敗</div>');
        }
      },
      error: function(xhr, status, error) {
        console.error('載入團隊詳情失敗:', error);
        $('#teamsList').html('<div class="alert alert-danger">載入團隊資料時發生錯誤</div>');
      }
    });
  }
  
  // 載入未加入的學生列表
  function loadUnjoinedStudents(count) {
    if (count === 0) {
      $('#teamsList').html('<div class="alert alert-info">所有學生皆已加入團隊</div>');
      return;
    }
    
    selectedGroupId = null;
    
    // 顯示團隊區塊並確保是展開狀態
    const $section = $('#teamsSection');
    const $icon = $('#teamsToggleIcon');
    $section.show().removeClass('collapsed');
    $icon.text('一');
    $('#selectedGroupName').text(`未加入團隊 - ${count}人`);
    
    // 顯示載入中
    $('#teamsList').html('<div class="loading"><i class="fas fa-spinner fa-spin fa-2x"></i><p>載入中...</p></div>');
    
    $.ajax({
      url: 'pages/class_data.php',
      method: 'GET',
      data: {
        show_unjoined: '1'
      },
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          displayUnjoinedStudents(response.students);
        } else {
          $('#teamsList').html('<div class="alert alert-danger">載入未加入學生資料失敗</div>');
        }
      },
      error: function(xhr, status, error) {
        console.error('載入未加入學生失敗:', error);
        $('#teamsList').html('<div class="alert alert-danger">載入未加入學生資料時發生錯誤</div>');
      }
    });
  }
  
  // 顯示未加入的學生列表
  function displayUnjoinedStudents(students) {
    if (!students || students.length === 0) {
      $('#teamsList').html('<div class="alert alert-info">所有學生皆已加入團隊</div>');
      return;
    }

    let html = '<div class="students-list">';
    const defaultAvatar = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
    students.forEach(student => {
      const avatarHtml = student.u_img 
        ? `<img src="headshot/${escapeHtml(student.u_img)}" alt="${escapeHtml(student.u_name)}" onerror="this.src='${defaultAvatar}'">`
        : `<img src="${defaultAvatar}" alt="${escapeHtml(student.u_name)}">`;
      const classLabel = student.class_name ? `<span class="member-class text-muted ms-1" style="font-size: 0.85em;">${escapeHtml(student.class_name)}</span>` : '';
      
      html += `
        <div class="member-item">
          <div class="member-avatar">
            ${avatarHtml}
          </div>
          <div class="member-info">
            <div class="member-name">${escapeHtml(student.u_name)}${classLabel}</div>
            <div class="member-id">學號：${escapeHtml(student.u_ID)}</div>
          </div>
        </div>
      `;
    });
    html += '</div>';

    $('#teamsList').html(html);
  }

  // 顯示團隊列表
  function displayTeams(teams) {
  if (!teams || teams.length === 0) {
    $('#teamsList').html('<div class="alert alert-info">此類組尚無團隊</div>');
    return;
  }

  let html = '';
  teams.forEach(team => {
    // 處理指導老師資訊
    let teacherInfoHtml = '';
    if (team.teachers && team.teachers.length > 0) {
      const teacherNames = team.teachers.map(t => escapeHtml(t.u_name)).join('、');
      teacherInfoHtml = `<div class="team-teacher-info">指導老師：${teacherNames}</div>`;
    }
    
    html += `
      <div class="team-card">
        <div class="team-card-header">
          <h6>${escapeHtml(team.team_name)}</h6>
          ${teacherInfoHtml}
        </div>
        <div class="members-list">
    `;
    
    if (team.members && team.members.length > 0) {
      team.members.forEach(member => {
        const defaultAvatar = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
        const avatarHtml = member.u_img 
          ? `<img src="headshot/${escapeHtml(member.u_img)}" alt="${escapeHtml(member.u_name)}" onerror="this.src='${defaultAvatar}'">`
          : `<img src="${defaultAvatar}" alt="${escapeHtml(member.u_name)}">`;
        const classLabel = member.class_name ? `<span class="member-class text-muted ms-1" style="font-size: 0.85em;">${escapeHtml(member.class_name)}</span>` : '';
        
        html += `
          <div class="member-item">
            <div class="member-avatar">
              ${avatarHtml}
            </div>
            <div class="member-info">
              <div class="member-name">${escapeHtml(member.u_name)}${classLabel}</div>
              <div class="member-id">學號：${escapeHtml(member.u_ID)}</div>
            </div>
          </div>
        `;
      });
    } else {
      html += '<div class="text-muted">尚無成員</div>';
    }
    
    html += `
        </div>
      </div>
    `;
  });

  $('#teamsList').html(html);
}

  // 顯示錯誤訊息
  function showError(message) {
  const $title = $('#statistics-title');
  $title.text(message).css('color', '#dc3545');
}

  // HTML 轉義
  function escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
  }
  
})(); // 結束 IIFE

