
// /js/pages/admin_notify.js
window.initPageScript = function () {
  let htmlInited = false;
  function ensureHtmlEditor() {
    if (htmlInited) return;
    if (!window.$ || !$.fn || !$.fn.summernote) return;
    $('#htmlEditor').summernote({
      height: 260,
      placeholder: '輸入 HTML 內容...',
      toolbar: [
        ['style', ['bold','italic','underline','clear']],
        ['para', ['ul','ol','paragraph']],
        ['insert', ['link','picture','table']],
        ['view', ['codeview']]
      ]
    });
    htmlInited = true;
  }

  $('input[name="content_mode"]').off('change.notify').on('change.notify', function () {
    const mode = $(this).val();
    if (mode === 'text') {
      $('#plainTextarea').removeClass('d-none');
      $('#htmlEditor').addClass('d-none');
    } else {
      ensureHtmlEditor();
      $('#plainTextarea').addClass('d-none');
      $('#htmlEditor').removeClass('d-none');
    }
  });
  $('#plainTextarea').removeClass('d-none');
  $('#htmlEditor').addClass('d-none');

  async function submitNotify(closeAfter = false) {
    const $form = $('#notifyForm');
    const mode = $('input[name="content_mode"]:checked').val();
    const content = mode === 'text'
      ? $('#plainTextarea').val()
      : ($('#htmlEditor').hasClass('d-none') ? '' : ($('#htmlEditor').summernote ? $('#htmlEditor').summernote('code') : $('#htmlEditor').val()));

    const fd = new FormData($form[0]);
    fd.set('mode', mode);
    fd.set('content', content);

    try {
      const resp = await fetch('api.php?do=notify_save', { method: 'POST', body: fd });
      const json = await resp.json();
      if (json.success) {
        if (window.Swal) Swal.fire({ icon:'success', title:'已新增', timer:1400, showConfirmButton:false });
        if (closeAfter) {
          const modal = bootstrap.Modal.getInstance(document.getElementById('notifyModal'));
          modal && modal.hide();
        } else {
          $form[0].reset();
          if ($('#htmlEditor').summernote) $('#htmlEditor').summernote('reset');
          $('#plainTextarea').removeClass('d-none');
          $('#htmlEditor').addClass('d-none');
        }
        if (typeof reloadNotifyList === 'function') reloadNotifyList();
      } else {
        if (window.Swal) Swal.fire({ icon:'error', title:'新增失敗', text: json.message || '請稍後再試' });
      }
    } catch {
      if (window.Swal) Swal.fire({ icon:'error', title:'連線錯誤', text:'無法連到伺服器' });
    }
  }

  $('#btnSave').off('click.notify').on('click.notify', () => submitNotify(false));
  $('#btnSaveAndBack').off('click.notify').on('click.notify', () => submitNotify(true));

  document.getElementById('notifyModal').addEventListener('show.bs.modal', () => {
    const $form = $('#notifyForm');
    $form[0].reset();
    if ($('#htmlEditor').summernote) $('#htmlEditor').summernote('reset');
    $('#plainTextarea').removeClass('d-none');
    $('#htmlEditor').addClass('d-none');
    $('#modeText').prop('checked', true);
  });
};
