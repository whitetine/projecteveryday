/*!
    * Start Bootstrap - SB Admin v7.0.7 (https://startbootstrap.com/template/sb-admin)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-sb-admin/blob/master/LICENSE)
    */
    // 
// Scripts
// 

window.addEventListener("DOMContentLoaded", event => {
  // Toggle the side navigation
  const sidebarToggle = document.body.querySelector("#sidebarToggle");
  if (sidebarToggle) {
    sidebarToggle.addEventListener("click", event => {
      event.preventDefault();
      document.body.classList.toggle("sb-sidenav-toggled");
      localStorage.setItem("sb|sidebar-toggle", document.body.classList.contains("sb-sidenav-toggled"));
    });
  }

  // SweetAlert global hash check
  const hash = window.location.hash;
  if (hash.includes("result=")) {
    const [base, query] = hash.split("?");
    const params = new URLSearchParams(query);
    const result = params.get("result");
    const msg = decodeURIComponent(params.get("msg") ?? "");

    Swal.fire({
      icon: result === "success" ? "success" : "error",
      title: result === "success" ? "更新成功！" : "更新失敗",
      text: msg,
      confirmButtonText: "確定"
    }).then(() => {
      // 清除 hash 中的 ?querystring 保留 base
      window.location.hash = base || "";
    });
  }
});
