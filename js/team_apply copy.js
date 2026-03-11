const { createApp } = Vue;

const app = createApp({
    data() {
        return {
            teachers: [],
            members: [], // [{u_ID, u_name}]
            projectName: '',
            comment: '',
            selectedImage: null,
            imagePreviewUrl: null,
            isSubmitting: false
        };
    },
   mounted() {
    // 這個元件只負責「可填表單」那一種情境
    this.loadTeachers();
    this.setupImagePreview();
},

    methods: {
        renderTeacherOptions(selectEl, placeholderText) {
            if (!selectEl) return;
            selectEl.innerHTML = '';

            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = placeholderText;
            selectEl.appendChild(defaultOption);

            if (!this.teachers || this.teachers.length === 0) {
                return;
            }

            this.teachers.forEach(teacher => {
                const option = document.createElement('option');
                option.value = teacher.u_ID;

                const pending = teacher.pending_count ?? 0;
                const confirmed = teacher.team_count ?? 0;

                option.textContent = `${teacher.u_name || teacher.u_ID}（申請中 ${pending} / 已通過 ${confirmed}）`;
                selectEl.appendChild(option);
            });
        },
        async loadTeachers() {
            try {
                const apiPath = window.TEAM_APPLY_CONFIG?.apiPath || '../api.php';
                console.log('載入指導老師，API 路徑:', apiPath);
                
                const response = await fetch(`${apiPath}?do=get_teachers`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('API 回應:', data);
                
                if (data.ok) {
                    this.teachers = data.teachers || [];
                    console.log('載入到', this.teachers.length, '位老師');
                    
                    const teacherSelect = document.getElementById('teacher_id');
                    const coTeacherSelect = document.getElementById('co_teacher_id');

                    if (this.teachers.length > 0) {
                        this.renderTeacherOptions(teacherSelect, '請選擇指導老師');
                        this.renderTeacherOptions(coTeacherSelect, '請選擇副指導老師（可留空）');
                        console.log('已填充', this.teachers.length, '位老師到下拉選單');
                    } else {
                        console.warn('沒有老師資料');
                        if (teacherSelect) {
                            this.renderTeacherOptions(teacherSelect, '目前沒有可用的指導老師');
                            teacherSelect.firstChild.disabled = true;
                        }
                        if (coTeacherSelect) {
                            this.renderTeacherOptions(coTeacherSelect, '目前沒有可用的指導老師');
                            coTeacherSelect.firstChild.disabled = true;
                        }
                    }
                } else {
                    console.error('API 返回錯誤:', data.msg);
                    Swal.fire({
                        icon: 'error',
                        title: '載入失敗',
                        text: data.msg || '無法載入指導老師列表',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#667eea'
                    });
                }
            } catch (error) {
                console.error('載入指導老師錯誤:', error);
                Swal.fire({
                    icon: 'error',
                    title: '錯誤',
                    text: '載入指導老師列表時發生錯誤：' + error.message,
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
            }
        },
        async addMember() {
            const input = document.getElementById('memberInput');
            const studentId = input.value.trim();
            
            if (!studentId) {
                Swal.fire({
                    icon: 'warning',
                    title: '請輸入學號',
                    text: '請輸入要新增的團隊成員學號',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            // 檢查是否已存在
            if (this.members.some(m => m.u_ID === studentId)) {
                Swal.fire({
                    icon: 'warning',
                    title: '成員已存在',
                    text: '該學號已在成員列表中',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            // 檢查是否為申請人自己
            if (studentId === window.TEAM_APPLY_CONFIG.u_ID) {
                Swal.fire({
                    icon: 'warning',
                    title: '無需添加自己',
                    text: '申請人會自動加入團隊，無需手動添加',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            // 檢查成員數量限制（最多3個學生，不包括申請人）
            const maxMembers = 3;
            if (this.members.length >= maxMembers) {
                Swal.fire({
                    icon: 'warning',
                    title: '成員數量已達上限',
                    text: `團隊最多只能有 ${maxMembers} 個成員（不包括申請人）`,
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            try {
                const formData = new FormData();
                formData.append('student_id', studentId);

                const response = await fetch(`${window.TEAM_APPLY_CONFIG.apiPath}?do=get_student_info`, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.ok) {
                    // json_ok() 返回格式是 {ok: true, student: {...}}
                    this.members.push(data.student);
                    input.value = '';
                    this.renderMemberList();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: '查詢失敗',
                        text: data.msg || '無法找到該學號的學生',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#667eea'
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '錯誤',
                    text: '查詢學生資訊時發生錯誤',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
            }
        },
        removeMember(index) {
            this.members.splice(index, 1);
        },
        renderMemberList() {
            const memberList = document.getElementById('memberList');
            if (!memberList) return;
            
            if (this.members.length === 0) {
                memberList.innerHTML = '';
                return;
            }
            
            memberList.innerHTML = this.members.map((member, index) => `
                <div class="member-tag">
                    <span class="member-name">${this.escapeHtml(member.u_name || member.u_ID)}</span>
                    <span class="member-id">${this.escapeHtml(member.u_ID)}</span>
                    <button type="button" class="btn-remove-member" onclick="window.vueAppInstance.removeMember(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `).join('');
        },
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        setupImagePreview() {
            const fileInput = document.getElementById('apply_image');
            const preview = document.getElementById('imagePreview');
            const previewImg = document.getElementById('previewImg');
            const removeBtn = document.getElementById('removeImageBtn');

           fileInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
        // 允許的 MIME 類型
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];

        if (!allowedTypes.includes(file.type)) {
            Swal.fire({
                icon: 'error',
                title: '檔案格式錯誤',
                text: '僅接受 JPG、JPEG、PNG 格式的圖片',
                confirmButtonText: '確定',
                confirmButtonColor: '#667eea'
            });
            fileInput.value = '';
            return;
        }

        this.selectedImage = file;
        const reader = new FileReader();
        reader.onload = (e) => {
            this.imagePreviewUrl = e.target.result;
            previewImg.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
});


            removeBtn.addEventListener('click', () => {
                fileInput.value = '';
                this.selectedImage = null;
                this.imagePreviewUrl = null;
                preview.style.display = 'none';
            });
        },
        async submitForm(e) {
            e.preventDefault();

            if (this.isSubmitting) return;

            // 驗證
            const teacherId = document.getElementById('teacher_id').value;
            if (!teacherId) {
                Swal.fire({
                    icon: 'warning',
                    title: '請選擇指導老師',
                    text: '請選擇您的專題指導老師',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            const groupId = document.getElementById('group_id').value;
            if (!groupId) {
                Swal.fire({
                    icon: 'warning',
                    title: '請選擇類組',
                    text: '請選擇您的專題類組',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            const coTeacherEl = document.getElementById('co_teacher_id');
            const coTeacherId = coTeacherEl ? coTeacherEl.value : '';
            if (coTeacherId && coTeacherId === teacherId) {
                Swal.fire({
                    icon: 'warning',
                    title: '請確認副指導老師',
                    text: '副指導老師不可與指導老師相同，若無副指導老師請留空',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            if (this.members.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: '請新增團隊成員',
                    text: '請至少新增一個團隊成員',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            const projectName = document.getElementById('project_name').value.trim();
            if (!projectName) {
                Swal.fire({
                    icon: 'warning',
                    title: '請輸入專題名稱',
                    text: '請輸入您的專題名稱',
                    confirmButtonText: '確定',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            const fileInput = document.getElementById('apply_image');
if (!fileInput.files || fileInput.files.length === 0) {
    Swal.fire({
        icon: 'warning',
        title: '請上傳申請表照片',
        text: '請上傳專題申請表的紙本照片',
        confirmButtonText: '確定',
        confirmButtonColor: '#667eea'
    });
    return;
}

// 再檢查一次格式
const file = fileInput.files[0];
const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
if (!allowedTypes.includes(file.type)) {
    Swal.fire({
        icon: 'error',
        title: '檔案格式錯誤',
        text: '僅接受 JPG、JPEG、PNG 格式的圖片',
        confirmButtonText: '確定',
        confirmButtonColor: '#667eea'
    });
    return;
}

            // 確認提交
            const result = await Swal.fire({
                icon: 'question',
                title: '確認提交',
                text: '確定要提交專題申請嗎？提交後將無法修改。',
                showCancelButton: true,
                confirmButtonText: '確定提交',
                cancelButtonText: '取消',
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                reverseButtons: true
            });

            if (!result.isConfirmed) return;

            this.isSubmitting = true;
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 提交中...';

            try {
                const formData = new FormData();
                formData.append('teacher_id', teacherId);
                formData.append('co_teacher_id', coTeacherId);
                formData.append('group_id', groupId);
                formData.append('project_name', projectName);
                formData.append('comment', this.comment);
                formData.append('member_ids', JSON.stringify(this.members.map(m => m.u_ID)));
                formData.append('apply_image', fileInput.files[0]);

                const response = await fetch(`${window.TEAM_APPLY_CONFIG.apiPath}?do=submit_application`, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.ok) {
                    await Swal.fire({
                        icon: 'success',
                        title: '提交成功',
                        text: '您的申請已成功提交，請等待科辦審核。',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#10b981'
                    });
                    // 重新載入頁面以顯示唯讀表單
                    location.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: '提交失敗',
                        text: data.msg || '提交申請時發生錯誤',
                        confirmButtonText: '確定',
                        confirmButtonColor: '#ef4444'
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: '錯誤',
                    text: '提交申請時發生錯誤：' + error.message,
                    confirmButtonText: '確定',
                    confirmButtonColor: '#ef4444'
                });
            } finally {
                this.isSubmitting = false;
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> 提交申請';
            }
        },
        resetForm() {
            document.getElementById('teamApplyForm').reset();
            this.members = [];
            this.comment = '';
            this.selectedImage = null;
            this.imagePreviewUrl = null;
            const preview = document.getElementById('imagePreview');
            if (preview) {
                preview.style.display = 'none';
            }
            // 重新載入指導老師/副指導老師選單
            this.renderTeacherOptions(document.getElementById('teacher_id'), '請選擇指導老師');
            this.renderTeacherOptions(document.getElementById('co_teacher_id'), '請選擇副指導老師（可留空）');
            // 重新渲染成員列表
            this.renderMemberList();
        },
        async loadGroups() {
            // 類組選單已在 PHP 中生成，不需要額外載入
        }
    }
});

// 等待 DOM 載入完成後再掛載
let vueApp = null;

// 確保在 DOM 完全載入後執行
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(initApp, 100); // 稍微延遲確保所有元素都已渲染
    });
} else {
    setTimeout(initApp, 100); // 稍微延遲確保所有元素都已渲染
}

function initApp() {
    const readonlySection = document.getElementById('readonlyFormSection');
    if (readonlySection) {
        // 唯讀頁：只顯示已送出的那筆
        loadReadonlyFormData();
        return;
    } else {
        // 可填表單頁
        vueApp = app.mount('.team-apply-container');
        window.vueAppInstance = vueApp;

        const resetBtn = document.getElementById('resetBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                vueApp.resetForm();
            });
        }

        const addMemberBtn = document.getElementById('addMemberBtn');
        if (addMemberBtn) {
            addMemberBtn.addEventListener('click', () => {
                vueApp.addMember();
            });
        }

        const form = document.getElementById('teamApplyForm');
        if (form) {
            form.addEventListener('submit', (e) => {
                vueApp.submitForm(e);
            });
        }

        vueApp.renderMemberList();

        // 🔽 如果有退件審核卡，就載入退件資料
        const reviewCard = document.getElementById('reviewResultCard');
        if (reviewCard) {
            loadReadonlyFormData();      // 從 API 抓最後一筆申請（後端要回退件那筆）
            reviewCard.style.display = 'block';
        }
    }
}
0
// 載入唯讀表單資料（不使用 Vue）
async function loadReadonlyFormData() {
    try {
        console.log('開始載入唯讀表單資料...');
        const apiPath = window.TEAM_APPLY_CONFIG?.apiPath || '../api.php';
        const response = await fetch(`${apiPath}?do=get_my_application`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('API 回應:', data);
        
        if (data.ok && data.application) {
            const app = data.application;
            console.log('申請資料:', app);
            
            // 填充指導老師
            if (app.teacher) {
                const teacherInput = document.getElementById('readonly_teacher');
                if (teacherInput) {
                    teacherInput.value = `${app.teacher.u_name || ''} (${app.teacher.u_ID || ''})`;
                    console.log('已填充指導老師:', teacherInput.value);
                }
            } else {
                console.warn('沒有指導老師資料');
            }

            // 填充副指導老師（若有）
            const coTeacherInput = document.getElementById('readonly_co_teacher');
            if (coTeacherInput) {
                if (app.co_teacher) {
                    coTeacherInput.value = `${app.co_teacher.u_name || ''} (${app.co_teacher.u_ID || ''})`;
                } else if (app.co_teacher_id) {
                    coTeacherInput.value = app.co_teacher_id;
                } else {
                    coTeacherInput.value = '未選填';
                }
            }
            
            // 填充類組
            if (app.group) {
                const groupInput = document.getElementById('readonly_group');
                if (groupInput) {
                    groupInput.value = app.group.group_name || '';
                    console.log('已填充類組:', groupInput.value);
                }
            } else {
                console.warn('沒有類組資料');
            }
            
            // 填充團隊成員
            if (app.members && app.members.length > 0) {
                const memberListDiv = document.getElementById('readonly_memberList');
                if (memberListDiv) {
                    memberListDiv.innerHTML = app.members.map(m => `
                        <div class="member-tag">
                            <span>${escapeHtml(m.u_name || m.u_ID)} (${escapeHtml(m.u_ID)})</span>
                        </div>
                    `).join('');
                    console.log('已填充團隊成員:', app.members.length, '人');
                }
            } else {
                console.warn('沒有團隊成員資料');
                const memberListDiv = document.getElementById('readonly_memberList');
                if (memberListDiv) {
                    memberListDiv.innerHTML = '<p class="text-muted">目前沒有成員</p>';
                }
            }
            
            // 填充專題名稱
            const projectNameInput = document.getElementById('readonly_project_name');
            if (projectNameInput) {
                projectNameInput.value = app.tap_name || '';
                console.log('已填充專題名稱:', projectNameInput.value);
            }
            
            // 填充說明文字
            const commentTextarea = document.getElementById('readonly_comment');
            if (commentTextarea) {
                commentTextarea.value = app.tap_des || '';
                console.log('已填充說明文字');
            }
            
            // 顯示申請表照片
            if (app.tap_url) {
                const img = document.getElementById('readonly_previewImg');
                const preview = document.getElementById('readonly_imagePreview');
                if (img && preview) {
                    // 確保路徑正確（如果是相對路徑，加上前綴）
                    let imageUrl = app.tap_url;
                    if (!imageUrl.startsWith('http') && !imageUrl.startsWith('/')) {
                        imageUrl = '../' + imageUrl;
                    }
                    img.src = imageUrl;
                    img.onerror = function() {
                        console.error('圖片載入失敗:', imageUrl);
                        this.src = 'images/placeholder.png';
                    };
                    preview.style.display = 'block';
                    console.log('已顯示申請表照片:', imageUrl);
                }
            } else {
                console.warn('沒有申請表照片');
            }
            // 審核結果卡（退件時用；如果頁面上有這些元素就填）
const reviewResultCard = document.getElementById('reviewResultCard');
const reviewRemarkEl = document.getElementById('reviewRemarkText');
const userCommentEchoEl = document.getElementById('userCommentEcho');
const reviewerNameEl = document.getElementById('reviewerName');
const reviewTimeEl = document.getElementById('reviewTime');

// 只有在退件狀態（tap_status = 2）時才顯示審核結果卡片
if (app.tap_status === 2 && reviewResultCard) {
    reviewResultCard.style.display = 'block';
    
    // 從 API 返回的資料中取得審核資訊
    const reviewerName = (app.reviewer && app.reviewer.u_name) ? app.reviewer.u_name : '—';
    const reviewTime   = app.tap_rp_d || app.tap_update_d || '—';
    const reviewRemark = app.tap_remark || '';
    const userComment  = app.tap_des || '';

    if (reviewerNameEl) reviewerNameEl.textContent = reviewerName;
    if (reviewTimeEl)   reviewTimeEl.textContent   = reviewTime;
    if (reviewRemarkEl) reviewRemarkEl.textContent = reviewRemark || '審核老師尚未留下備註。';
    if (userCommentEchoEl) userCommentEchoEl.textContent = userComment || '（無備註內容）';
}

            // 顯示狀態
            const statusDiv = document.getElementById('readonly_status');
            if (statusDiv) {
                let statusText = '';
                let statusClass = '';
                if (app.tap_status === 1) {
                    statusText = '待審核';
                    statusClass = 'badge bg-warning';
                } else if (app.tap_status === 2) {
                    statusText = '已退件';
                    statusClass = 'badge bg-danger';
                } else if (app.tap_status === 3) {
                    statusText = '已通過';
                    statusClass = 'badge bg-success';
                } else {
                    statusText = '未知狀態';
                    statusClass = 'badge bg-secondary';
                }
                statusDiv.innerHTML = `<span class="${statusClass}">${statusText}</span>`;
                console.log('已顯示狀態:', statusText);
            }
        } else {
            console.warn('沒有申請資料或資料格式錯誤');
        }
    } catch (error) {
        console.error('載入申請資料錯誤:', error);
        Swal.fire({
            icon: 'error',
            title: '載入失敗',
            text: '無法載入申請資料：' + error.message,
            confirmButtonText: '確定',
            confirmButtonColor: '#ef4444'
        });
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

