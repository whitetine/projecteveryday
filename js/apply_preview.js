// js/apply_review.js
document.addEventListener('DOMContentLoaded', () => {
  // 圖片點擊放大
  document.querySelector('#applyTable')?.addEventListener('click', e => {
    const img = e.target.closest('img.preview');
    if (img) showModal(img.src);
  });

  // 通過/退件（用 data-action）
  document.querySelector('#applyTable')?.addEventListener('click', e => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const tr = btn.closest('tr');
    const id = tr?.cells?.[0]?.innerText;
    const action = btn.dataset.action;
    if (id) updateStatus(id, action, btn);
  });

  ['searchBox', 'statusFilter', 'typeFilter'].forEach(id => {
    const el = document.getElementById(id);
    el && el.addEventListener('input', filterTable);
  });

  filterTable();
});

// 以下保留你原本函式
function showModal(src){ /* ... */ }
function closeModal(){ /* ... */ }
function filterTable(){ /* ... */ }
function reorderTable(){ /* ... */ }
function updateStatus(id, action, btn){
  const tr = btn.closest('tr'), name = tr.cells[2].innerText;
  Swal.fire({
    title:'確認操作',
    text: action==='approve' ? `確定將「${name}」通過？` : `確定將「${name}」退件？`,
    icon: action==='approve' ? 'question' : 'warning',
    showCancelButton:true
  }).then(r=>{
    if(!r.isConfirmed) return;
    // 注意：這裡的路徑請依實際位置調整
    fetch('preview.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `apply_ID=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}&ajax=1`
    })
    .then(res=>res.json())
    .then(data=>{
      if(data.ok){
        tr.querySelector('.status-cell').innerText = data.status_text;
        tr.querySelector('.op-cell').innerText = '-';
        Swal.fire('成功', `${name}${data.status_text}`, 'success');
        reorderTable();
      }else{
        Swal.fire('失敗','更新失敗','error');
      }
    })
    .catch(()=>Swal.fire('錯誤','無法連線','error'));
  });
}
