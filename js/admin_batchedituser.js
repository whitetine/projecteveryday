// 批量編輯使用者腳本
(function() {
    'use strict';
    
    let currentUserIndex = 0;
    let usersData = [];
    let userFormsData = {}; // 儲存每個使用者的表單資料
    let options = {};
    
    function init() {
        if (!window.batchEditUsers || !window.batchEditOptions) {
            console.error('批量編輯資料未載入');
            return;
        }
        
        usersData = window.batchEditUsers;
        options = window.batchEditOptions;
        
        if (usersData.length === 0) {
            document.getElementById('userFormContainer').innerHTML = '<div class="alert alert-warning">沒有選中的使用者</div>';
            return;
        }
        
        // 初始化表單資料
        usersData.forEach((user, index) => {
            userFormsData[user.u_ID] = {
                name: user.u_name || '',
                gmail: user.u_gmail || '',
                profile: user.u_profile || '',
                cohort_id: user.cohort_ID || '',
                class_id: user.class_ID || '',
                grade: user.enroll_grade || '',
                role_id: user.role_ID || '',
                status_id: user.u_status || '',
                password: '',
                avatar: null,
                clear_avatar: '0'
            };
        });
        
        // 載入第一個使用者
        loadUserForm(0);
        updateNavigationButtons();
        
        // 綁定事件
        document.getElementById('btnPrevUser')?.addEventListener('click', () => {
            if (currentUserIndex > 0) {
                saveCurrentUserForm();
                loadUserForm(currentUserIndex - 1);
                updateNavigationButtons();
            }
        });
        
        document.getElementById('btnNextUser')?.addEventListener('click', () => {
            if (currentUserIndex < usersData.length - 1) {
                saveCurrentUserForm();
                loadUserForm(currentUserIndex + 1);
                updateNavigationButtons();
            }
        });
        
        document.getElementById('btnCancel')?.addEventListener('click', () => {
            if (window.Swal) {
                Swal.fire({
                    title: '確認取消',
                    text: '確定要取消編輯嗎？未儲存的變更將遺失。',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#6c757d',
                    cancelButtonColor: '#28a745',
                    confirmButtonText: '確定取消',
                    cancelButtonText: '繼續編輯',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (typeof loadSubpage === 'function') {
                            loadSubpage('pages/admin_usermanage.php');
                        } else {
                            location.href = '#pages/admin_usermanage.php';
                        }
                    }
                });
            } else {
                if (confirm('確定要取消編輯嗎？未儲存的變更將遺失。')) {
                    if (typeof loadSubpage === 'function') {
                        loadSubpage('pages/admin_usermanage.php');
                    } else {
                        location.href = '#pages/admin_usermanage.php';
                    }
                }
            }
        });
        
        document.getElementById('btnSaveAll')?.addEventListener('click', () => {
            saveCurrentUserForm();
            submitAllUsers();
        });
    }
    
    function loadUserForm(index) {
        if (index < 0 || index >= usersData.length) return;
        
        currentUserIndex = index;
        const user = usersData[index];
        const formData = userFormsData[user.u_ID];
        
        // 更新計數器
        document.getElementById('userCounter').textContent = `(${index + 1} / ${usersData.length})`;
        
        // 生成表單HTML
        const formHtml = generateUserForm(user, formData, index);
        document.getElementById('userFormContainer').innerHTML = formHtml;
        
        // 初始化表單事件
        initFormEvents(user.u_ID);
    }
    
    function generateUserForm(user, formData, index) {
        const statusMap = {
            0: '休學',
            1: '就讀中',
            2: '離校',
            3: '畢業'
        };
        
        // 生成選項HTML
        const cohortOptions = options.cohorts.map(ch => 
            `<option value="${ch.cohort_ID}" ${formData.cohort_id == ch.cohort_ID ? 'selected' : ''}>${escapeHtml(ch.cohort_name)}</option>`
        ).join('');
        
        const classOptions = options.classes.map(c => 
            `<option value="${c.c_ID}" ${formData.class_id == c.c_ID ? 'selected' : ''}>${escapeHtml(c.c_name)}</option>`
        ).join('');
        
        const roleOptions = options.roles.map(r => 
            `<option value="${r.role_ID}" ${formData.role_id == r.role_ID ? 'selected' : ''}>${escapeHtml(r.role_name)}</option>`
        ).join('');
        
        const statusOptions = options.statuses
            .filter(s => s.status_ID !== 4)
            .map(s => {
                const displayName = statusMap[s.status_ID] || s.status_name;
                return `<option value="${s.status_ID}" ${formData.status_id == s.status_ID ? 'selected' : ''}>${escapeHtml(displayName)}</option>`;
            })
            .join('');
        
        const gradeOptions = Array.from({length: 6}, (_, i) => {
            const grade = i + 1;
            return `<option value="${grade}" ${formData.grade == grade ? 'selected' : ''}>${grade}年級</option>`;
        }).join('');
        
        const avatarSrc = user.u_img ? `headshot/${escapeHtml(user.u_img)}` : 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
        
        return `
            <input type="hidden" name="user_${user.u_ID}_u_ID" value="${escapeHtml(user.u_ID)}">
            <div class="row g-4">
                <!-- 頭像區塊 -->
                <div class="col-12 col-md-4">
                    <div class="avatar-section">
                        <img id="avatarPreview_${index}"
                            src="${avatarSrc}"
                            alt="用戶頭像" 
                            class="avatar-preview"
                            onerror="this.src='https://cdn-icons-png.flaticon.com/512/1144/1144760.png'"
                            style="width: 180px; height: 180px; object-fit: cover; border-radius: 50%; border: 5px solid #fff; box-shadow: 0 8px 24px rgba(0,0,0,0.15);">
                        
                        <div class="avatar-upload">
                            <input type="file" name="user_${user.u_ID}_avatar" id="avatarInput_${index}" accept="image/*" class="form-control">
                            <small>建議 1:1 圖片，JPG/PNG/WebP，最大 5MB</small>
                        </div>
                        
                        <input type="hidden" name="user_${user.u_ID}_clear_avatar" id="clear_avatar_${index}" value="${formData.clear_avatar}">
                        <button type="button" class="btn btn-outline-danger btn-clear-avatar" id="btnClearAvatar_${index}">
                            <i class="fa-solid fa-trash me-2"></i>清除頭貼
                        </button>
                    </div>
                </div>

                <!-- 基本資料區塊 -->
                <div class="col-12 col-md-8">
                    <div class="form-section">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-id-card"></i>學號/ID
                                </label>
                                <input type="text" 
                                    class="form-control form-control-enhanced" 
                                    value="${escapeHtml(user.u_ID)}" 
                                    readonly>
                                <small class="text-muted">此欄位無法修改</small>
                            </div>

                            <div class="col-12 col-sm-6">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-user"></i>姓名
                                </label>
                                <input type="text" 
                                    name="user_${user.u_ID}_name" 
                                    class="form-control form-control-enhanced" 
                                    value="${escapeHtml(formData.name)}"
                                    required>
                            </div>

                            <div class="col-12 col-sm-6">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-lock"></i>密碼
                                </label>
                                <input type="password" 
                                    name="user_${user.u_ID}_password" 
                                    class="form-control form-control-enhanced" 
                                    id="pwd_${index}" 
                                    placeholder="留空表示不更改密碼"
                                    autocomplete="new-password">
                                <small class="text-muted">留空則不修改密碼</small>
                            </div>

                            <div class="col-12 col-sm-6">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-envelope"></i>信箱
                                </label>
                                <input type="email" 
                                    name="user_${user.u_ID}_gmail" 
                                    class="form-control form-control-enhanced" 
                                    value="${escapeHtml(formData.gmail)}"
                                    placeholder="example@email.com">
                            </div>

                            <div class="col-12">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-info-circle"></i>自我介紹
                                </label>
                                <textarea name="user_${user.u_ID}_profile" 
                                    rows="4" 
                                    class="form-control form-control-enhanced"
                                    placeholder="輸入自我介紹...">${escapeHtml(formData.profile)}</textarea>
                            </div>

                            <div class="col-12 col-sm-4">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-calendar-alt"></i>學級
                                </label>
                                <select name="user_${user.u_ID}_cohort_id" class="form-select form-select-enhanced">
                                    <option value="">無學級</option>
                                    ${cohortOptions}
                                </select>
                            </div>

                            <div class="col-12 col-sm-4">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-graduation-cap"></i>目前班級
                                </label>
                                <div class="d-flex gap-2">
                                    <select name="user_${user.u_ID}_class_id" class="form-select form-select-enhanced flex-grow-1">
                                        <option value="">無班級</option>
                                        ${classOptions}
                                    </select>
                                    <select name="user_${user.u_ID}_grade" class="form-select form-select-enhanced" style="min-width: 100px;">
                                        <option value="">年級</option>
                                        ${gradeOptions}
                                    </select>
                                </div>
                            </div>

                            <div class="col-12 col-sm-4">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-user-tag"></i>角色
                                </label>
                                <select name="user_${user.u_ID}_role_id" class="form-select form-select-enhanced" required>
                                    <option value="">請選擇角色</option>
                                    ${roleOptions}
                                </select>
                            </div>

                            <div class="col-12 col-sm-4">
                                <label class="form-label-enhanced">
                                    <i class="fa-solid fa-toggle-on"></i>狀態
                                </label>
                                <select name="user_${user.u_ID}_status_id" class="form-select form-select-enhanced" required>
                                    ${statusOptions}
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    function initFormEvents(userId) {
        const index = currentUserIndex;
        const avatarInput = document.getElementById(`avatarInput_${index}`);
        const avatarPreview = document.getElementById(`avatarPreview_${index}`);
        const btnClearAvatar = document.getElementById(`btnClearAvatar_${index}`);
        const clearAvatar = document.getElementById(`clear_avatar_${index}`);
        
        if (avatarInput && avatarPreview) {
            avatarInput.addEventListener('change', function(e) {
                const file = e.target.files?.[0];
                if (file) {
                    if (file.size > 5 * 1024 * 1024) {
                        if (window.Swal) {
                            Swal.fire('檔案過大', '頭貼大小不能超過 5MB', 'warning');
                        } else {
                            alert('頭貼大小不能超過 5MB');
                        }
                        e.target.value = '';
                        return;
                    }
                    
                    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                    if (!validTypes.includes(file.type)) {
                        if (window.Swal) {
                            Swal.fire('格式錯誤', '只接受 JPG、PNG 或 WebP 格式', 'warning');
                        } else {
                            alert('只接受 JPG、PNG 或 WebP 格式');
                        }
                        e.target.value = '';
                        return;
                    }
                    
                    avatarPreview.src = URL.createObjectURL(file);
                    if (clearAvatar) clearAvatar.value = '0';
                    userFormsData[userId].avatar = file;
                }
            });
        }
        
        if (btnClearAvatar && clearAvatar) {
            btnClearAvatar.addEventListener('click', function(e) {
                e.preventDefault();
                if (window.Swal) {
                    Swal.fire({
                        title: '確認清除',
                        text: '確定要清除頭貼嗎？',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: '確定清除',
                        cancelButtonText: '取消',
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            clearAvatar.value = '1';
                            if (avatarInput) avatarInput.value = '';
                            if (avatarPreview) avatarPreview.src = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
                            userFormsData[userId].clear_avatar = '1';
                            userFormsData[userId].avatar = null;
                        }
                    });
                } else {
                    if (confirm('確定要清除頭貼嗎？')) {
                        clearAvatar.value = '1';
                        if (avatarInput) avatarInput.value = '';
                        if (avatarPreview) avatarPreview.src = 'https://cdn-icons-png.flaticon.com/512/1144/1144760.png';
                        userFormsData[userId].clear_avatar = '1';
                        userFormsData[userId].avatar = null;
                    }
                }
            });
        }
    }
    
    function saveCurrentUserForm() {
        if (currentUserIndex < 0 || currentUserIndex >= usersData.length) return;
        
        const user = usersData[currentUserIndex];
        const userId = user.u_ID;
        
        // 從表單讀取資料
        const nameInput = document.querySelector(`input[name="user_${userId}_name"]`);
        const gmailInput = document.querySelector(`input[name="user_${userId}_gmail"]`);
        const profileInput = document.querySelector(`textarea[name="user_${userId}_profile"]`);
        const cohortSelect = document.querySelector(`select[name="user_${userId}_cohort_id"]`);
        const classSelect = document.querySelector(`select[name="user_${userId}_class_id"]`);
        const gradeSelect = document.querySelector(`select[name="user_${userId}_grade"]`);
        const roleSelect = document.querySelector(`select[name="user_${userId}_role_id"]`);
        const statusSelect = document.querySelector(`select[name="user_${userId}_status_id"]`);
        const passwordInput = document.querySelector(`input[name="user_${userId}_password"]`);
        const clearAvatarInput = document.getElementById(`clear_avatar_${currentUserIndex}`);
        
        if (nameInput) userFormsData[userId].name = nameInput.value;
        if (gmailInput) userFormsData[userId].gmail = gmailInput.value;
        if (profileInput) userFormsData[userId].profile = profileInput.value;
        if (cohortSelect) userFormsData[userId].cohort_id = cohortSelect.value;
        if (classSelect) userFormsData[userId].class_id = classSelect.value;
        if (gradeSelect) userFormsData[userId].grade = gradeSelect.value;
        if (roleSelect) userFormsData[userId].role_id = roleSelect.value;
        if (statusSelect) userFormsData[userId].status_id = statusSelect.value;
        if (passwordInput) userFormsData[userId].password = passwordInput.value;
        if (clearAvatarInput) userFormsData[userId].clear_avatar = clearAvatarInput.value;
    }
    
    function updateNavigationButtons() {
        const btnPrev = document.getElementById('btnPrevUser');
        const btnNext = document.getElementById('btnNextUser');
        
        if (btnPrev) {
            btnPrev.style.display = currentUserIndex > 0 ? 'block' : 'none';
        }
        if (btnNext) {
            btnNext.style.display = currentUserIndex < usersData.length - 1 ? 'block' : 'none';
        }
    }
    
    function submitAllUsers() {
        // 收集所有使用者的資料
        const formData = new FormData();
        const userIds = [];
        
        usersData.forEach(user => {
            const data = userFormsData[user.u_ID];
            userIds.push(user.u_ID);
            
            formData.append(`users[${user.u_ID}][u_ID]`, user.u_ID);
            formData.append(`users[${user.u_ID}][name]`, data.name);
            formData.append(`users[${user.u_ID}][gmail]`, data.gmail);
            formData.append(`users[${user.u_ID}][profile]`, data.profile);
            formData.append(`users[${user.u_ID}][cohort_id]`, data.cohort_id || '');
            formData.append(`users[${user.u_ID}][class_id]`, data.class_id || '');
            formData.append(`users[${user.u_ID}][grade]`, data.grade || '');
            formData.append(`users[${user.u_ID}][role_id]`, data.role_id);
            formData.append(`users[${user.u_ID}][status_id]`, data.status_id);
            formData.append(`users[${user.u_ID}][password]`, data.password);
            formData.append(`users[${user.u_ID}][clear_avatar]`, data.clear_avatar);
            
            if (data.avatar) {
                formData.append(`users[${user.u_ID}][avatar]`, data.avatar);
            }
        });
        
        formData.append('u_IDs', userIds.join(','));
        
        if (window.Swal) {
            Swal.fire({
                title: '確認批量儲存',
                text: `確定要儲存 ${usersData.length} 位使用者的資料嗎？`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '確定',
                cancelButtonText: '取消',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    doSubmit(formData);
                }
            });
        } else {
            if (confirm(`確定要儲存 ${usersData.length} 位使用者的資料嗎？`)) {
                doSubmit(formData);
            }
        }
    }
    
    function doSubmit(formData) {
        if (window.Swal) {
            Swal.fire({
                title: '處理中...',
                text: '請稍候',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }
        
        fetch('pages/admin_batchupdateuser.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (window.Swal) {
                Swal.close();
            }
            
            if (data.success) {
                if (window.Swal) {
                    Swal.fire({
                        title: '成功',
                        text: data.message || '批量儲存成功',
                        icon: 'success',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#ffc107'
                    }).then(() => {
                        if (typeof loadSubpage === 'function') {
                            loadSubpage('pages/admin_usermanage.php');
                        } else {
                            location.href = '#pages/admin_usermanage.php';
                        }
                    });
                } else {
                    alert('批量儲存成功');
                    if (typeof loadSubpage === 'function') {
                        loadSubpage('pages/admin_usermanage.php');
                    } else {
                        location.href = '#pages/admin_usermanage.php';
                    }
                }
            } else {
                if (window.Swal) {
                    Swal.fire({
                        title: '錯誤',
                        text: data.message || '批量儲存失敗',
                        icon: 'error',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#ffc107'
                    });
                } else {
                    alert(data.message || '批量儲存失敗');
                }
            }
        })
        .catch(error => {
            if (window.Swal) {
                Swal.close();
                Swal.fire({
                    title: '錯誤',
                    text: '發生錯誤：' + error.message,
                    icon: 'error',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#ffc107'
                });
            } else {
                alert('發生錯誤：' + error.message);
            }
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // 等待 DOM 載入完成
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
