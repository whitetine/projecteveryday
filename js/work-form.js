// js/pages/work-form.js
document.addEventListener('DOMContentLoaded', () => {
  const root      = document.querySelector('#work-form-page');
  if (!root) return;

  const $ = (sel) => root.querySelector(sel);
  const form      = $('#work-main-form');
  const tfId      = $('#wf-work_id');
  const tfTitle   = $('#wf-title');
  const tfContent = $('#wf-content');
  const tfFile    = $('#wf-file');

  const btnDraft  = $('#wf-btn-draft');
  const btnSubmit = $('#wf-btn-submit');
  const btnClear  = $('#btn-clear-file');
  const btnRemove = $('#btn-remove-file');
  const badgeRO   = $('#wf-readonly-badge');

  const curWrap   = $('#wf-current-file');
  const curLink   = $('#wf-file-link');

  // 讀資料
  fetch('#pages/work_form_data.php')
    .then(r => r.json())
    .then(j => {
      if (!j.ok) {
        alert(j.msg || '讀取失敗');
        return;
      }
      if (j.msg) {
        // 你的 head.php 若已載入 SweetAlert2，就會有 Swal 可用
        if (window.Swal) Swal.fire({icon:'info', title:'提示', text:j.msg, confirmButtonText:'知道了'});
      }
      const t = j.today;
      if (t) {
        tfId.value      = t.work_ID || '';
        tfTitle.value   = t.work_title || '';
        tfContent.value = t.work_content || '';
        if (t.work_url) {
          curWrap.classList.remove('d-none');
          curLink.href = t.work_url;
          curLink.textContent = t.work_url.split('/').pop();
        }
      }
      applyReadonly(j.readOnly === true);
    })
    .catch(() => alert('讀取失敗，請稍後再試'));

  function applyReadonly(ro){
    tfTitle.readOnly   = ro;
    tfContent.readOnly = ro;
    tfFile.disabled    = ro;
    btnClear.disabled  = ro;

    if (ro) {
      btnDraft.classList.add('d-none');
      btnSubmit.classList.add('d-none');
      badgeRO.classList.remove('d-none');
    } else {
      btnDraft.classList.remove('d-none');
      btnSubmit.classList.remove('d-none');
      badgeRO.classList.add('d-none');
    }
  }

  // 清空尚未上傳的檔案
  const updateClearState = () => {
    const hasFile = tfFile.files && tfFile.files.length > 0;
    btnClear.disabled = tfFile.disabled || !hasFile;
  };
  tfFile.addEventListener('change', updateClearState);
  btnClear.addEventListener('click', () => {
    try { tfFile.value=''; tfFile.dispatchEvent(new Event('change')); } catch(e){}
    updateClearState();
    if (window.Swal) Swal.fire({icon:'success', title:'已清空選擇', timer:900, showConfirmButton:false});
  });
  updateClearState();

  // >50MB 擋下
  form.addEventListener('submit', (e) => {
    const f = tfFile && tfFile.files && tfFile.files[0];
    if (f && f.size > 50 * 1024 * 1024) {
      e.preventDefault();
      if (window.Swal) Swal.fire({icon:'warning', title:'檔案過大', text:'檔案超過 50MB，請清空選擇或把雲端連結貼在內容區。'});
    }
  });

  // 移除已上傳檔案（走既有 work_save.php）
  btnRemove && btnRemove.addEventListener('click', () => {
    if (!tfId.value) return;
    const go = () => {
      const fm = document.createElement('form');
      fm.method = 'post';
      fm.action = '#pages/work_save.php';
      fm.classList.add('d-none');
      fm.innerHTML = `
        <input type="hidden" name="action" value="remove_file">
        <input type="hidden" name="work_id" value="${tfId.value}">
      `;
      document.body.appendChild(fm);
      fm.submit();
    };
    if (window.Swal) {
      Swal.fire({
        icon:'question',
        title:'確定要移除目前已上傳的檔案嗎？',
        showCancelButton:true,
        cancelButtonText:'取消',
        confirmButtonText:'移除',
        confirmButtonColor:'#dc3545',
        reverseButtons:true,
        focusCancel:true
      }).then((r)=>{ if(r.isConfirmed) go(); });
    } else {
      if (confirm('確定移除已上傳檔案？')) go();
    }
  });
});
