const app = Vue.createApp({
  //1015update
  data() {
    return {
      applyUser: window.CURRENT_USER.u_ID || '',
      applyOther: '',
      selectedFileID: '',
      imagePreview: '',
      previewPercent: 50,
      files: [],
      selectedFileUrl: ''
    };
  },

//-------
  methods: {
    async submitForm() {
      const formEl = document.getElementById('applyForm');
      const fd = new FormData(formEl); // 自動包含檔案與文字欄位

      //1015update

      if(!fd.has('apply_user')){
        fd.append('apply_user',this.applyUser);
      }

      //----------


      try {
        const res = await fetch('pages/api/upload.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
          Swal.fire('成功', data.message, 'success');

          //1015update
          formEl.reset();
          this.applyOther = '';
          this.imagePreview = '';
          this.selectedFileID = '';
          //-----------

        } else {
          Swal.fire('失敗', data.message || '請檢查表單', 'error');
        }
      } catch (e) {
        Swal.fire('錯誤', '無法連線到伺服器', 'error');
      }
    },
    //1015update
    previewImage(e){
      const file = e.target.files[0];
      if(file){
        const reader = new FileReader();
        reader.onload = (event)=>{
          this.imagePreview = event.target.result;
        };
        reader.readAsDataURL(file);
      }
    }

  },
  watch:{
    selectedFileID(newVal){
      if(newVal){
        this.selectedFileUrl = `templates/file_${newVal}.pdf`;
      }else{
        this.selectedFileUrl = '';
      }
    }
  },
  mounted(){
    this.fetchFiles();
  }

    //---------------

});
app.mount('#app');