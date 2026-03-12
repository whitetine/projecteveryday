(function() {
  // 初始化頁面
  window.initPageScript = function() {
    loadTeamData();
  };

  // 載入組別數據
  async function loadTeamData() {
    const membersContainer = document.getElementById('members-container');
    const teamNameEl = document.getElementById('student-team-name');
    const teamTitleEl = document.getElementById('student-team-title');
    
    if (!membersContainer) {
      console.warn('找不到 members-container 元素');
      return; // 不中斷後續多檔案 render
    }

    try {
      const response = await fetch('pages/student_data.php');
      const data = await response.json();

      if (!data.success) {
        if (data.msg === 'no_team') {
          membersContainer.innerHTML = `
            <div class="text-center p-5">
              <i class="fas fa-users-slash fa-3x mb-3 text-muted"></i>
              <h5 class="text-muted">您目前沒有所屬組別</h5>
              <p class="text-muted">請聯繫管理員加入組別</p>
            </div>
          `;
        } else {
          membersContainer.innerHTML = `
            <div class="alert alert-danger">
              <i class="fas fa-exclamation-circle"></i> 載入失敗：${data.msg || '未知錯誤'}
            </div>
          `;
        }
        return;
      }

      // 更新組別名稱（若有）
      if (data.teamName) {
        const nameText = String(data.teamName);
        if (teamNameEl) teamNameEl.textContent = nameText;
        if (teamTitleEl) teamTitleEl.textContent = nameText;
      }

      // 顯示成員列表（包括指導老師）
      renderMembers(data.members || [], data.teachers || []);

    } catch (error) {
      console.error('載入組別數據失敗:', error);
      if (membersContainer) {
        membersContainer.innerHTML = `
          <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> 載入失敗，請重新整理頁面
          </div>
        `;
      }
    }
  }

  // 渲染成員列表
  function renderMembers(members, teachers) {
    const membersContainer = document.getElementById('members-container');
    
    if (!membersContainer) {
      console.warn('找不到 members-container 元素');
      return; // 不中斷後續多檔案 render
    }
    
    // 確保數組存在
    const memberList = Array.isArray(members) ? members : [];
    const teacherList = Array.isArray(teachers) ? teachers : [];
    
    // 如果沒有任何成員或指導老師，顯示空狀態
    if (memberList.length === 0 && teacherList.length === 0) {
      membersContainer.innerHTML = `
        <div class="text-center p-5">
          <i class="fas fa-user-slash fa-3x mb-3 text-muted"></i>
          <h5 class="text-muted">目前沒有組別成員</h5>
        </div>
      `;
      return;
    }
    
    let html = '';
    
    // 先渲染學生成員
    if (memberList.length > 0) {
      memberList.forEach((member) => {
        // 處理頭貼路徑
        const hasAvatar = member.u_img && member.u_img.trim() !== '';
        const defaultAvatarUrl = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
        let avatarUrl = defaultAvatarUrl;
        
        if (hasAvatar) {
          const cleanImgName = member.u_img.trim().replace(/\s+/g, '%20');
          avatarUrl = `headshot/${cleanImgName}`;
        }

        html += `
          <div class="member-card">
            <div class="member-avatar">
              <img src="${escapeHtml(avatarUrl)}" 
                   alt="${escapeHtml(member.u_name)}" 
                   onerror="this.src='${defaultAvatarUrl}'">
            </div>
            <div class="member-info">
              <h5 class="member-name">${escapeHtml(member.u_name)}</h5>
              <div class="member-details">
                <div class="detail-item">
                  <i class="fas fa-id-card"></i>
                  <span>學號：${escapeHtml(member.u_ID)}</span>
                </div>
                <div class="detail-item profile-item">
                  <i class="fas fa-user-circle"></i>
                  <span class="profile-text ${!member.u_profile || !member.u_profile.trim() ? 'empty-profile' : ''}">自介：${member.u_profile && member.u_profile.trim() ? escapeHtml(member.u_profile) : '他甚麼也沒留下...'}</span>
                </div>
              </div>
            </div>
          </div>
        `;
      });
    }
    
    // 然後渲染指導老師（放在同一排）
    if (teacherList.length > 0) {
      teacherList.forEach((teacher) => {
        // 處理頭貼路徑
        const hasAvatar = teacher.u_img && teacher.u_img.trim() !== '';
        const defaultAvatarUrl = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
        let avatarUrl = defaultAvatarUrl;
        
        if (hasAvatar) {
          const cleanImgName = teacher.u_img.trim().replace(/\s+/g, '%20');
          avatarUrl = `headshot/${cleanImgName}`;
        }

        html += `
          <div class="member-card teacher-card">
            <div class="member-avatar">
              <img src="${escapeHtml(avatarUrl)}" 
                   alt="${escapeHtml(teacher.u_name)}" 
                   onerror="this.src='${defaultAvatarUrl}'">
            </div>
            <div class="member-info">
              <h5 class="member-name">
                ${escapeHtml(teacher.u_name)}
                <span class="teacher-badge"><i class="fas fa-chalkboard-teacher"></i> 指導老師</span>
              </h5>
              <div class="member-details">
                <div class="detail-item">
                  <i class="fas fa-id-card"></i>
                  <span>帳號：${escapeHtml(teacher.u_ID)}</span>
                </div>
                <div class="detail-item profile-item">
                  <i class="fas fa-user-circle"></i>
                  <span class="profile-text ${!teacher.u_profile || !teacher.u_profile.trim() ? 'empty-profile' : ''}">自介：${teacher.u_profile && teacher.u_profile.trim() ? escapeHtml(teacher.u_profile) : '他甚麼也沒留下...'}</span>
                </div>
              </div>
            </div>
          </div>
        `;
      });
    }
    
    membersContainer.innerHTML = html;
  }

  // 工具函數：轉義 HTML
  function escapeHtml(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  }


  // 頁面載入時自動執行
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      if (document.getElementById('members-container')) {
        window.initPageScript();
      }
    });
  } else {
    if (document.getElementById('members-container')) {
      window.initPageScript();
    }
  }
})();

