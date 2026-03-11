window.mountApplyUploader = function (mountSelector) {
  const app = Vue.createApp({
    //1015update
    data() {
      // ⭐ 直接在 data() 中讀取申請人姓名，這是整個顯示的關鍵！
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
      return {
        selectedFileID: '',
        applyUser: userName,  // ⭐ 這行是整個顯示的關鍵！
        applyOther: '',
        previewPercent: 100,  // 圖片預覽縮放百分比，預設100%
        imagePreview: null,
        imageNaturalWidth: 0,
        imageNaturalHeight: 0,
        imageWidth: 0,
        imageHeight: 0,
        files: [],
        selectedFileUrl: '',
        pdfZoom: 100,  // PDF 縮放百分比
        hasSubmitted: false  // 是否已有組員送出過此文件
      };
    },
    computed: {
      // 獲取當前選中的文件對象
      selectedFile() {
        if (!this.selectedFileID || !this.files.length) return null;
        return this.files.find(f => String(f.doc_ID) === String(this.selectedFileID)) || null;
      },

      // 是否顯示預期成果提醒
      needExresultdataWarning() {
        if (!this.selectedFile) return false;

        const val = this.selectedFile.exresultdata;
        return val === true || val === 1 || val === '1' || val === 'true';
      },

      // 是否可以提交
      canSubmit() {
        if (!this.selectedFile) return false;
        // 檢查是否已過期
        if (this.selectedFile.doc_end_d && this.isExpired(this.selectedFile.doc_end_d)) {
          return false;
        }
        // 檢查是否尚未開放
        if (this.selectedFile.doc_start_d && !this.isStarted(this.selectedFile.doc_start_d)) {
          return false;
        }
        return true;
      },

      scaledImageStyle() {
        if (!this.imagePreview) {
          return { maxWidth: '100%', height: 'auto', width: 'auto' };
        }
        const scale = this.previewPercent / 100;

        // 如果有原始尺寸，直接計算縮放後的實際尺寸
        if (this.imageNaturalWidth && this.imageNaturalHeight) {
          let displayWidth = this.imageNaturalWidth * scale;
          let displayHeight = this.imageNaturalHeight * scale;

          return {
            width: displayWidth + 'px',
            height: displayHeight + 'px',
            maxWidth: 'none',
            objectFit: 'contain',
            display: 'block'
          };
        }

        // 如果還沒有載入尺寸，先顯示原始大小
        return {
          maxWidth: '100%',
          width: 'auto',
          height: 'auto',
          objectFit: 'contain',
          display: 'block'
        };
      }
    },
    //-------
    methods: {
      // 檢查是否已過期
      isExpired(endDate) {
        if (!endDate) return false;
        const now = new Date();
        const end = new Date(endDate);
        return now > end;
      },
      // 檢查是否已開放
      isStarted(startDate) {
        if (!startDate) return true; // 如果沒有設定開放時間，視為已開放
        const now = new Date();
        const start = new Date(startDate);
        return now >= start;
      },
      // 格式化日期時間
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
      // 獲取下拉框選項的顯示文字（包含時間信息）
      getFileOptionText(file) {
        let text = file.doc_name || '未知文件';
        if (file.is_required == 1) {
          text += '（必填）';
        }

        // 添加時間信息
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
        // 檢查截止時間
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

        // 檢查開放時間
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

        // 如果已經有 mode（覆蓋或新送），直接執行提交
        if (mode === 'overwrite' || mode === 'new') {
          // 繼續執行提交邏輯
        } else {
          // 先檢查是否存在
          if (!this.selectedFileID) {
            Swal.fire('錯誤', '請先選擇文件', 'error');
            return;
          }

          try {
            // 計算正確的 API 路徑
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

            // 根據 exists 決定顯示哪個確認窗口
            let result;
            if (data.exists === true) {
              // exists=true → 跳「是否覆蓋？」（已上傳過）
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
              // exists=false → 跳「是否確定送出？」（尚未填寫過）
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
        const fd = new FormData(formEl); // 自動包含檔案與文字欄位

        //1015update
        // 確保傳送 u_ID 而不是 u_name（因為 docsubdata 表的 dcsub_u_ID 需要 ID）
        const userId = (window.CURRENT_USER && window.CURRENT_USER.u_ID) ? window.CURRENT_USER.u_ID : '';
        if (userId) {
          fd.set('apply_user', userId); // 使用 u_ID
        } else if (!fd.has('apply_user')) {
          fd.append('apply_user', this.applyUser);
        }

        // 根據 mode 添加標誌
        fd.append('mode', mode);  // 使用 mode 參數：'overwrite' 或 'new'
        if (mode === 'overwrite') {
          fd.append('overwrite', '1');  // 同時保留 overwrite 參數以兼容舊代碼
        }

        //----------

        try {
          const res = await fetch('pages/somefunction/upload.php', { method: 'POST', body: fd });
          const data = await res.json();

          // 調試：記錄響應
          console.log('Upload response:', data);

          // 🔹 處理後端最後防線檢查：DUPLICATE_SUBMIT 錯誤
          if (data.ok === false && data.code === 'DUPLICATE_SUBMIT') {
            // 如果前端檢查漏了，後端返回錯誤，提示用戶
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
            // 如果後端返回 duplicate，但前端應該已經檢查過，這裡作為備用處理
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

            //1015update
            formEl.reset();
            this.applyOther = '';
            this.imagePreview = '';
            this.imageNaturalWidth = 0;
            this.imageNaturalHeight = 0;
            this.imageWidth = 0;
            this.imageHeight = 0;
            this.selectedFileID = '';
            this.hasSubmitted = false;  // 重置提交狀態
            // 重置後重新設置申請人姓名
            if (window.CURRENT_USER && window.CURRENT_USER.u_name) {
              this.applyUser = window.CURRENT_USER.u_name;
            }
            //-----------

          } else {
            Swal.fire('失敗', data.message || '請檢查表單', 'error');
          }
        } catch (e) {
          Swal.fire('錯誤', '無法連線到伺服器', 'error');
        }
      },
      //1015update
      previewImage(e) {
        const file = e.target.files[0];
        if (file) {
          const reader = new FileReader();
          reader.onload = (event) => {
            this.imagePreview = event.target.result;
            // 重置圖片尺寸
            this.imageWidth = 0;
            this.imageHeight = 0;
            this.imageNaturalWidth = 0;
            this.imageNaturalHeight = 0;
            // 重置縮放比例為預設值
            this.previewPercent = 100;
          };
          reader.readAsDataURL(file);
        } else {
          this.imagePreview = null;
          this.imageWidth = 0;
          this.imageHeight = 0;
        }
      },
      onImageLoad(e) {
        // 圖片載入完成後，獲取原始尺寸
        const img = e.target;
        this.imageNaturalWidth = img.naturalWidth;
        this.imageNaturalHeight = img.naturalHeight;
        this.imageWidth = img.naturalWidth;
        this.imageHeight = img.naturalHeight;

        // 如果是橫向圖片（寬度大於高度），自動調整縮放比例
        if (this.imageNaturalWidth > this.imageNaturalHeight) {
          // 獲取實際容器寬度
          const container = img.closest('.preview-box');
          const containerWidth = container ? container.clientWidth - 30 : 800; // 減去padding

          // 計算適合的縮放比例，讓橫向圖片適中顯示
          const scale = Math.min(100, (containerWidth / this.imageNaturalWidth) * 100);
          // 取最接近的整數，最小50%，最大100%
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
      zoomPdfIn() {
        if (this.pdfZoom < 200) {
          this.pdfZoom = Math.min(200, this.pdfZoom + 10);
        }
      },
      zoomPdfOut() {
        if (this.pdfZoom > 50) {
          this.pdfZoom = Math.max(50, this.pdfZoom - 10);
        }
      },
      resetPdfZoom() {
        this.pdfZoom = 100;
      },
      updateSelectedFileUrl(docId) {
        if (!docId) {
          this.selectedFileUrl = '';
          return;
        }

        console.log('updateSelectedFileUrl called with docId:', docId);
        console.log('Current files length:', this.files.length);

        // 如果 files 還沒載入，先等待
        if (this.files.length === 0) {
          console.log('Files not loaded yet, waiting...');
          return;
        }

        // 嘗試找到對應的文件
        const file = this.files.find(f => String(f.doc_ID) === String(docId));
        console.log('Found file:', file);

        if (file) {
          // 計算正確的基礎路徑
          const pathname = window.location.pathname;
          // 從 pathname 提取專案根目錄（例如：/projecteveryday/ 或 /）
          let projectRoot = '';
          if (pathname.includes('main.php')) {
            // 提取 main.php 之前的路徑作為專案根目錄
            const mainIndex = pathname.indexOf('main.php');
            projectRoot = pathname.substring(0, mainIndex);
            // 確保以 / 開頭，以 / 結尾
            if (!projectRoot.startsWith('/')) projectRoot = '/' + projectRoot;
            if (!projectRoot.endsWith('/')) projectRoot = projectRoot + '/';
          } else if (pathname.includes('/pages/')) {
            // 從 /pages/ 提取專案根目錄
            const pagesIndex = pathname.indexOf('/pages/');
            projectRoot = pathname.substring(0, pagesIndex);
            if (!projectRoot.startsWith('/')) projectRoot = '/' + projectRoot;
            if (!projectRoot.endsWith('/')) projectRoot = projectRoot + '/';
          } else {
            // 默認假設在根目錄
            projectRoot = '/';
          }

          // 如果是舊的 file.php 管理的文件，才會有 doc_example / doc_url
          // 若為新的科辦文件表單（document_forms），通常不會有這兩個欄位 → 不顯示範例預覽即可
          if (!file.doc_example && !file.doc_url) {
            this.selectedFileUrl = '';
            console.log('No example URL for this document form, skip preview iframe.');
            return;
          }

          // 優先使用 doc_example，如果有設定
          if (file.doc_example && file.doc_example.trim() !== '') {
            let fileUrl = file.doc_example.trim();
            // 處理不同類型的路徑
            if (fileUrl.startsWith('http://') || fileUrl.startsWith('https://')) {
              // 已經是完整的 HTTP URL，直接使用
              this.selectedFileUrl = fileUrl;
            } else if (fileUrl.startsWith('/')) {
              // 絕對路徑，確保包含專案根目錄（如果專案不在網站根目錄）
              // 如果專案在根目錄，直接使用；否則需要調整
              if (projectRoot !== '/') {
                // 專案在子目錄下，需要在路徑前加上專案根目錄
                this.selectedFileUrl = projectRoot.replace(/\/$/, '') + fileUrl;
              } else {
                this.selectedFileUrl = fileUrl;
              }
            } else {
              // 相對路徑（如 uploads/doc/...），需要加上專案根目錄
              this.selectedFileUrl = projectRoot + fileUrl.replace(/^\.\.\//, '').replace(/^\//, '');
            }
            console.log('Using doc_example:', this.selectedFileUrl, 'projectRoot:', projectRoot);
          } else if (file.doc_url && file.doc_url.trim() !== '') {
            let fileUrl = file.doc_url.trim();
            // 同樣處理 doc_url
            if (fileUrl.startsWith('http://') || fileUrl.startsWith('https://')) {
              this.selectedFileUrl = fileUrl;
            } else if (fileUrl.startsWith('/')) {
              if (projectRoot !== '/') {
                this.selectedFileUrl = projectRoot.replace(/\/$/, '') + fileUrl;
              } else {
                this.selectedFileUrl = fileUrl;
              }
            } else {
              this.selectedFileUrl = projectRoot + fileUrl.replace(/^\.\.\//, '').replace(/^\//, '');
            }
            console.log('Using doc_url:', this.selectedFileUrl, 'projectRoot:', projectRoot);
          } else {
            // 如果沒有 URL，使用後備路徑
            let fallbackUrl = projectRoot + `templates/file_${docId}.pdf`;
            this.selectedFileUrl = fallbackUrl;
            console.log('File found but no URL, using fallback path:', fallbackUrl);
          }
        } else {
          // 如果找不到文件，使用後備路徑
          const pathname = window.location.pathname;
          let projectRoot = '';
          if (pathname.includes('main.php')) {
            const mainIndex = pathname.indexOf('main.php');
            projectRoot = pathname.substring(0, mainIndex);
            if (!projectRoot.startsWith('/')) projectRoot = '/' + projectRoot;
            if (!projectRoot.endsWith('/')) projectRoot = projectRoot + '/';
          } else if (pathname.includes('/pages/')) {
            const pagesIndex = pathname.indexOf('/pages/');
            projectRoot = pathname.substring(0, pagesIndex);
            if (!projectRoot.startsWith('/')) projectRoot = '/' + projectRoot;
            if (!projectRoot.endsWith('/')) projectRoot = projectRoot + '/';
          } else {
            projectRoot = '/';
          }
          let fallbackUrl = projectRoot + `templates/file_${docId}.pdf`;
          this.selectedFileUrl = fallbackUrl;
          console.log('File not found, using fallback path:', fallbackUrl);
        }

        console.log('Final selectedFileUrl:', this.selectedFileUrl);
      },
      scrollToPreview() {
        // 滾動到預覽區域
        this.$nextTick(() => {
          setTimeout(() => {
            const previewCard = document.getElementById('example-preview-card');
            if (previewCard) {
              previewCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          }, 300);
        });
      },
      handleIframeError(e) {
        console.error('Iframe load error:', e);
      },
      handleIframeLoad(e) {
        console.log('Iframe loaded successfully');
      },
      async checkDocSubmission(doc_ID) {
        if (!doc_ID) {
          this.hasSubmitted = false;
          return;
        }

        try {
          // 計算正確的 API 路徑
          let API_ROOT = 'api.php';
          const pathname = window.location.pathname;
          if (pathname.includes('main.php')) {
            API_ROOT = 'api.php';
          } else if (pathname.includes('/pages/')) {
            API_ROOT = '../api.php';
          } else {
            API_ROOT = 'api.php';
          }

          // 🔹 使用 check_exist API（與提交前檢查使用同一個 API，確保一致性）
          const res = await fetch(`${API_ROOT}?do=check_exist&doc_ID=${doc_ID}`, {
            cache: 'no-store'
          });

          if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
          }

          const data = await res.json();
          console.log('checkDocSubmission (check_exist) response:', data);

          // 🔹 根據 exists 狀態更新提示
          // exists=true 表示該組已填寫過此文件
          if (data.exists === true) {
            this.hasSubmitted = true;
            console.log('檢測到已提交，設置 hasSubmitted = true');
          } else {
            this.hasSubmitted = false;
            console.log('未檢測到已提交記錄，設置 hasSubmitted = false');
          }

        } catch (e) {
          console.error('checkDocSubmission error:', e);
          // 檢查失敗時不阻止提交，但記錄錯誤
          this.hasSubmitted = false;
        }
      },
      async fetchFiles() {
        try {
          // 改為使用科辦在 form_manage 設定的文件表單（document_forms）
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
            this.files = data.forms.map(file => ({
              ...file,
              exresultdata:
                file.exresultdata === true ||
                file.exresultdata === 1 ||
                file.exresultdata === '1' ||
                file.exresultdata === 'true'
            }));
          } else {
            this.files = [];
          }

          console.log('Final files array:', this.files);
          if (this.selectedFileID) {
            this.updateSelectedFileUrl(this.selectedFileID);
          }
        } catch (e) {
          console.error('fetchFiles error:', e);
          Swal.fire('錯誤', '無法載入表單列表：' + e.message, 'error');
        }
      }
    },
    watch: {
      selectedFileID(newVal, oldVal) {
        console.log('selectedFileID changed from', oldVal, 'to', newVal);
        if (newVal) {
          // 立即嘗試更新，如果 files 還沒載入，會在 files watch 中處理
          this.updateSelectedFileUrl(newVal);
          // 🔹 立即檢查該組是否已填寫過此文件
          this.checkDocSubmission(newVal);
        } else {
          this.selectedFileUrl = '';
          this.hasSubmitted = false;  // 重置提交狀態
          console.log('selectedFileID cleared, selectedFileUrl reset');
        }
      },
      files: {
        handler(newFiles, oldFiles) {
          console.log('files changed, length:', newFiles.length, 'selectedFileID:', this.selectedFileID);
          // 當 files 載入完成後，如果已經選擇了表單，更新預覽
          if (newFiles.length > 0 && this.selectedFileID) {
            console.log('Files loaded, updating preview for selectedFileID:', this.selectedFileID);
            // 使用 $nextTick 確保 Vue 已經更新
            this.$nextTick(() => {
              this.updateSelectedFileUrl(this.selectedFileID);
            });
          }
        },
        immediate: false,
        deep: true
      }
    },
    created() {
      // 確保申請人姓名在初始化時就被設置
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
      if (userName) {
        this.applyUser = userName;
      }
    },
    mounted() {
      // ⭐ 強制設置申請人姓名（頁面載入時立即顯示）
      const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';

      // 立即設置到 Vue（最重要！）
      if (userName) {
        this.applyUser = userName;
      }

      // 立即設置到 DOM
      const inputEl = document.getElementById('apply_user');
      if (inputEl) {
        if (userName) {
          inputEl.value = userName;
        } else if (inputEl.value) {
          // 如果 DOM 有值但 Vue 沒有，同步到 Vue
          this.applyUser = inputEl.value;
        }
      }

      // 使用 $nextTick 確保 DOM 更新後再次確認
      this.$nextTick(() => {
        const userName = (window.CURRENT_USER && window.CURRENT_USER.u_name) ? window.CURRENT_USER.u_name : '';
        if (userName) {
          this.applyUser = userName;
          const inputEl = document.getElementById('apply_user');
          if (inputEl) {
            inputEl.value = userName;
            // 觸發 input 事件確保 Vue v-model 同步
            inputEl.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      });

      // 使用 setTimeout 作為備用方案
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

    //---------------

  });
  app.mount(mountSelector || '#app');
};

