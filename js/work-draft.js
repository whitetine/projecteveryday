document.addEventListener("DOMContentLoaded", () => {
  const tableBody = document.querySelector("#draft-table tbody");
  const pager = document.querySelector("#draft-pager");

  async function loadDrafts(page = 1) {
    // const res = await fetch('#pages/work_draft_data.php?page=' + page + buildQueryFromFilter());
    const formEl = document.querySelector('#draft-filter');
    const qs = formEl ? new URLSearchParams(new FormData(formEl)).toString() : '';
    const res = await fetch(`pages/work_draft_data.php?page=${page}` + (qs ? `&${qs}` : ''));

    const data = await res.json();

    tableBody.innerHTML = "";
    if (data.draft) {
      tableBody.innerHTML += `
        <tr class="table-warning">
          <td>${data.draft.work_created_d}</td>
          <td>${data.draft.work_title}</td>
          <td>暫存</td>
          <td>${data.draft.work_url ? `<a href="${data.draft.work_url}" target="_blank">附件</a>` : "—"}</td>
          <td><span class="badge bg-warning">暫存</span></td>
          <td><a href="#work_form" class="btn btn-sm btn-outline-primary spa-link">繼續編輯</a></td>
        </tr>`;
    }

    if (data.rows.length === 0 && !data.draft) {
      tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">查無資料</td></tr>`;
    } else {
      data.rows.forEach(r => {
        tableBody.innerHTML += `
          <tr>
            <td>${r.work_created_d}</td>
            <td>${r.work_title}</td>
            <td>${r.work_content.substring(0, 50)}...</td>
            <td>${r.work_url ? `<a href="${r.work_url}" target="_blank">附件</a>` : "—"}</td>
            <td><span class="badge bg-success">已送出</span></td>
            <td><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewModal"
                  data-title="${r.work_title}" data-content="${r.work_content}" data-file="${r.work_url || ""}" data-created="${r.work_created_d}">
                  查看</button></td>
          </tr>`;
      });
    }

    pager.innerHTML = "";
    for (let i = 1; i <= data.pages; i++) {
      pager.innerHTML += i === data.page
        ? `<span class="active">${i}</span>`
        : `<a href="#" data-page="${i}">${i}</a>`;
    }
    pager.querySelectorAll("a").forEach(a => {
      a.addEventListener("click", e => {
        e.preventDefault();
        loadDrafts(a.dataset.page);
      });
    });
  }

  loadDrafts();

  // Modal 資料填充
  const modal = document.getElementById("viewModal");
  modal.addEventListener("show.bs.modal", e => {
    const btn = e.relatedTarget;
    modal.querySelector("#vm-title").textContent = btn.dataset.title;
    modal.querySelector("#vm-content").textContent = btn.dataset.content;
    modal.querySelector("#vm-meta").textContent = "建立時間：" + btn.dataset.created;
    modal.querySelector("#vm-file").innerHTML = btn.dataset.file
      ? `<a href="${btn.dataset.file}" target="_blank">下載附件</a>`
      : "附件：—";
  });
});
