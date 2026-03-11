window.mountApplyTestUploader = function(mountSelector) {
  const app = Vue.createApp({
    data() {
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
      return {
        selectedFileID: '',
        applyUser: userName,
        applyOther: '',
        previewPercent: 100,
        imagePreview: null,
        imageNaturalWidth: 0,
        imageNaturalHeight: 0,
        imageWidth: 0,
        imageHeight: 0,
        files: [],
        selectedFileUrl: '',
        hasSubmitted: false
      };
    },
    computed: {
      selectedFile() {
        if (!this.selectedFileID || !this.files.length) return null;
        return this.files.find(f => String(f.doc_ID) === String(this.selectedFileID)) || null;
      },
      canSubmit() {
        if (!this.selectedFile) return false;
        if (this.selectedFile.doc_end_d && this.isExpired(this.selectedFile.doc_end_d)) {
          return false;
        }
        if (this.selectedFile.doc_start_d && !this.isStarted(this.selectedFile.doc_start_d)) {
          return false;
        }
        return true;
      }
    },
    methods: {
      isExpired(endDate) {
        if (!endDate) return false;
        const now = new Date();
        const end = new Date(endDate);
        return now > end;
      },
      isStarted(startDate) {
        if (!startDate) return true;
        const now = new Date();
        const start = new Date(startDate);
        return now >= start;
      },
      formatDateTime(dateTimeStr) {
        if (!dateTimeStr) return '';
        const date = new Date(dateTimeStr);
        if (isNaN(date.getTime())) return dateTimeStr;
        
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        
        return `${year}-${month}-${day} ${hours}:${minutes}`;
      },
      getFileOptionText(file) {
        let text = file.doc_name || '未知文件';
        if (file.is_required == 1) {
          text += '（必填）';
        }
        
        const timeParts = [];
        if (file.doc_start_d) {
          timeParts.push('開放：' + this.formatDateTime(file.doc_start_d));
        }
        if (file.doc_end_d) {
          timeParts.push('截止：' + this.formatDateTime(file.doc_end_d));
        }
        
        if (timeParts.length > 0) {
          text += ' - ' + timeParts.join(' | ');
        }
        
        return text;
      },
      async submitForm(mode = null) {
        if (this.selectedFile && this.selectedFile.doc_end_d && this.isExpired(this.selectedFile.doc_end_d)) {
          Swal.fire({
            icon: 'error',
            title: '無法提交',
            text: '此文件已過期，無法提交',
            confirmButtonText: '確定',
            confirmButtonColor: '#3085d6'
          });
          return;
        }
        
        if (this.selectedFile && this.selectedFile.doc_start_d && !this.isStarted(this.selectedFile.doc_start_d)) {
          Swal.fire({
            icon: 'warning',
            title: '尚未開放',
            text: '此文件尚未開放，無法提交',
            confirmButtonText: '確定',
            confirmButtonColor: '#3085d6'
          });
          return;
        }
        
        if (mode === 'overwrite' || mode === 'new') {
          // 繼續執行提交邏輯
        } else {
          if (!this.selectedFileID) {
            Swal.fire('錯誤', '請先選擇文件', 'error');
            return;
          }

          try {
            let API_ROOT = 'api.php';
            const pathname = window.location.pathname;
            if (pathname.includes('main.php')) {
              API_ROOT = 'api.php';
            } else if (pathname.includes('/pages/')) {
              API_ROOT = '../api.php';
            } else {
              API_ROOT = 'api.php';
            }

            const res = await fetch(`${API_ROOT}?do=check_exist&doc_ID=${this.selectedFileID}`, { 
              cache: 'no-store' 
            });
            
            if (!res.ok) {
              throw new Error(`HTTP error! status: ${res.status}`);
            }
            
            const data = await res.json();
            console.log('check_exist response:', data);
            
            let result;
            if (data.exists === true) {
              result = await Swal.fire({
                icon: 'warning',
                title: '確認覆蓋',
                text: '此份文件該組已經上傳過，\n再次送出將【覆蓋】先前上傳的資料，\n是否確定要繼續？',
                showCancelButton: true,
                confirmButtonText: '確定覆蓋',
                cancelButtonText: '取消',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
              });
              
              if (!result.isConfirmed) {
                return;
              }
              mode = 'overwrite';
            } else {
              result = await Swal.fire({
                icon: 'question',
                title: '確認送出',
                text: '是否確定送出此份申請文件？',
                showCancelButton: true,
                confirmButtonText: '確定送出',
                cancelButtonText: '取消',
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
              });
              
              if (!result.isConfirmed) {
                return;
              }
              mode = 'new';
            }
          } catch (e) {
            console.error('check_exist error:', e);
            Swal.fire('錯誤', '檢查失敗，請稍後再試', 'error');
            return;
          }
        }

        const formEl = document.getElementById('applyForm');
        const fd = new FormData(formEl);

        const userId = (window.CURRENT_USER && window.CURRENT_USER.u_ID) ? window.CURRENT_USER.u_ID : '';
        if (userId) {
          fd.set('apply_user', userId);
        } else if (!fd.has('apply_user')) {
          fd.append('apply_user', this.applyUser);
        }

        fd.append('mode', mode);
        if (mode === 'overwrite') {
          fd.append('overwrite', '1');
        }

        try {
          const res = await fetch('pages/somefunction/upload.php', { method: 'POST', body: fd });
          const data = await res.json();
          
          console.log('Upload response:', data);
          
          if (data.ok === false && data.code === 'DUPLICATE_SUBMIT') {
            Swal.fire({
              icon: 'warning',
              title: '無法送出',
              text: data.message || '此文件本組已繳交',
              confirmButtonText: '確定',
              confirmButtonColor: '#3085d6'
            });
            return;
          }
          
          if (data.status === 'duplicate') {
            console.log('檢測到重複上傳（後端檢查）');
            const result = await Swal.fire({
              icon: 'warning',
              title: '是否要覆蓋原本已送出的文件？',
              text: '此文件本組已有成員送出，若繼續送出將覆蓋原本的文件。',
              showCancelButton: true,
              confirmButtonText: '覆蓋送出',
              cancelButtonText: '取消',
              confirmButtonColor: '#d33',
              cancelButtonColor: '#6c757d',
              reverseButtons: true
            });
            
            if (result.isConfirmed) {
              this.submitForm('overwrite');
            }
            return;
          }
          
          if (data.status === 'success') {
            Swal.fire({
              icon: 'success',
              title: mode === 'overwrite' ? '已覆蓋' : '已送出',
              text: data.message || (mode === 'overwrite' ? '文件已成功覆蓋！' : '您的申請已成功送出，請等待審核'),
              confirmButtonText: '確定',
              customClass: {
                popup: 'swal2-popup-success'
              }
            });

            formEl.reset();
            this.applyOther = '';
            this.imagePreview = '';
            this.imageNaturalWidth = 0;
            this.imageNaturalHeight = 0;
            this.imageWidth = 0;
            this.imageHeight = 0;
            this.selectedFileID = '';
            this.hasSubmitted = false;
            if (window.CURRENT_USER && window.CURRENT_USER.u_name) {
              this.applyUser = window.CURRENT_USER.u_name;
            }

          } else {
            Swal.fire('失敗', data.message || '請檢查表單', 'error');
          }
        } catch (e) {
          Swal.fire('錯誤', '無法連線到伺服器', 'error');
        }
      },
      previewImage(e){
        const file = e.target.files[0];
        if(file){
          const reader = new FileReader();
          reader.onload = (event)=>{
            this.imagePreview = event.target.result;
            this.imageWidth = 0;
            this.imageHeight = 0;
            this.imageNaturalWidth = 0;
            this.imageNaturalHeight = 0;
            this.previewPercent = 100;
          };
          reader.readAsDataURL(file);
        } else {
          this.imagePreview = null;
          this.imageWidth = 0;
          this.imageHeight = 0;
        }
      },
      onImageLoad(e){
        const img = e.target;
        this.imageNaturalWidth = img.naturalWidth;
        this.imageNaturalHeight = img.naturalHeight;
        this.imageWidth = img.naturalWidth;
        this.imageHeight = img.naturalHeight;
        
        if (this.imageNaturalWidth > this.imageNaturalHeight) {
          const container = img.closest('.preview-box');
          const containerWidth = container ? container.clientWidth - 30 : 800;
          const scale = Math.min(100, (containerWidth / this.imageNaturalWidth) * 100);
          this.previewPercent = Math.max(50, Math.min(100, Math.floor(scale)));
        }
      },
      zoomIn() {
        if (this.previewPercent < 200) {
          this.previewPercent = Math.min(200, this.previewPercent + 5);
        }
      },
      zoomOut() {
        if (this.previewPercent > 10) {
          this.previewPercent = Math.max(10, this.previewPercent - 5);
        }
      },
      resetZoom() {
        this.previewPercent = 100;
      },
      async checkDocSubmission(doc_ID) {
        if (!doc_ID) {
          this.hasSubmitted = false;
          return;
        }

        try {
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          } else {
            API_ROOT = 'api.php';
          }

          const res = await fetch(`${API_ROOT}?do=check_exist&doc_ID=${doc_ID}`, { 
            cache: 'no-store' 
          });
          
          if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
          }
          
          const data = await res.json();
          console.log('checkDocSubmission (check_exist) response:', data);
          
          if (data.exists === true) {
            this.hasSubmitted = true;
            console.log('檢測到已提交，設置 hasSubmitted = true');
          } else {
            this.hasSubmitted = false;
            console.log('未檢測到已提交記錄，設置 hasSubmitted = false');
          }
          
        } catch (e) {
          console.error('checkDocSubmission error:', e);
          this.hasSubmitted = false;
        }
      },
      async fetchFiles() {
        try {
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          } else {
            API_ROOT = 'api.php';
          }
          
          console.log('Fetching document forms from:', `${API_ROOT}?do=get_available_document_forms`, 'pathname:', pathname);
          const res = await fetch(`${API_ROOT}?do=get_available_document_forms`, { cache: 'no-store' });
          
          if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}, URL: ${API_ROOT}?do=get_available_document_forms`);
          }
          
          const data = await res.json();
          console.log('Fetched document forms data:', data);
          
          if (data.ok && Array.isArray(data.forms)) {
            this.files = data.forms;
          } else {
            this.files = [];
          }
          
          console.log('Final files array:', this.files);
        } catch (e) {
          console.error('fetchFiles error:', e);
          Swal.fire('錯誤', '無法載入表單列表：' + e.message, 'error');
        }
      }
    },
    watch:{
      selectedFileID(newVal, oldVal){
        console.log('selectedFileID changed from', oldVal, 'to', newVal);
        if(newVal){
          this.checkDocSubmission(newVal);
        }else{
          this.hasSubmitted = false;
          console.log('selectedFileID cleared');
        }
      },
      files: {
        handler(newFiles, oldFiles) {
          console.log('files changed, length:', newFiles.length, 'selectedFileID:', this.selectedFileID);
        },
        immediate: false,
        deep: true
      }
    },
    created(){
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
      if (userName) {
        this.applyUser = userName;
      }
    },
    mounted(){
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
      
      if (userName) {
        this.applyUser = userName;
      }
      
      const inputEl = document.getElementById('apply_user');
      if (inputEl) {
        if (userName) {
          inputEl.value = userName;
        } else if (inputEl.value) {
          this.applyUser = inputEl.value;
        }
      }
      
      this.$nextTick(() => {
        const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
        if (userName) {
          this.applyUser = userName;
          const inputEl = document.getElementById('apply_user');
          if (inputEl) {
            inputEl.value = userName;
            inputEl.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      });
      
      setTimeout(() => {
        const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
        if (userName) {
          this.applyUser = userName;
          const inputEl = document.getElementById('apply_user');
          if (inputEl) {
            inputEl.value = userName;
            inputEl.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      }, 50);
      
      this.fetchFiles();
    }
  });
  app.mount(mountSelector || '#app');
};
