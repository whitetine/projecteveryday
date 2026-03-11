/*!
    * Start Bootstrap - SB Admin v7.0.7 (https://startbootstrap.com/template/sb-admin)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-sb-admin/blob/master/LICENSE)
    */
    // 
// Scripts
// 

window.addEventListener("DOMContentLoaded", event => {
  // 注意：sidebar toggle 的處理已經移到 app.js 中統一管理
  // 這裡只保留 localStorage 的讀取（如果需要的話）
  // 避免重複綁定造成衝突
  
  // 如果 app.js 已經處理了 sidebar toggle，這裡就不需要再處理
  // 只保留其他必要的初始化邏輯

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
