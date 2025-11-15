function resolveWorkFormApiUrl() {
  const formEl = document.getElementById("work-main-form");
  const path = window.location.pathname || "";

  if (!formEl) {
    if (path.includes("/pages/")) {
      return "work_form_data.php";
    }
    return "pages/work_form_data.php";
  }

  const base = (formEl.dataset.apiBase || "").trim();
  console.log("work-form resolveApiUrl base:", base, "path:", path);
  if (!base || base === ".") {
    return path.includes("/pages/") ? "work_form_data.php" : "pages/work_form_data.php";
  }

  if (base === "pages" || base === "/pages") {
    return path.includes("/pages/") ? "work_form_data.php" : "pages/work_form_data.php";
  }

  if (base.toLowerCase().includes("work_draft")) {
    return "pages/work_form_data.php";
  }

  const suffix = base.endsWith("/") ? "work_form_data.php" : "/work_form_data.php";
  return `${base}${suffix}`;
}

async function loadData() {
  try {
    const apiUrl = resolveWorkFormApiUrl();
    console.log("work-form loadData ->", apiUrl + "?action=get");
    const res = await fetch(apiUrl + "?action=get", { credentials: "same-origin" });
    const j = await res.json();

    if (!j.success) throw new Error(j.msg || "資料載入失敗");

    document.querySelector("#work_id").value = j.work.work_ID || "";
    document.querySelector("#work_title").value = j.work.work_title || "";
    document.querySelector("#work_content").value = j.work.work_content || "";

    if (j.readOnly) {
      document.querySelector("#work_title").readOnly = true;
      document.querySelector("#work_content").readOnly = true;
      document.querySelector("#action-buttons").classList.add("d-none");
      document.querySelector("#doneBadge").classList.remove("d-none");
    }
  } catch (e) {
    Swal.fire("錯誤", e.message, "error");
  }
}

async function saveData(type) {
  try {
    const form = document.querySelector("#work-main-form");
    const fd = new FormData(form);
    fd.append("action", type);

    const res = await fetch(resolveWorkFormApiUrl(), {
      method: "POST",
      credentials: "same-origin",
      body: fd
    });

    const j = await res.json();

    if (j.success) {
      // 成功後顯示提示訊息，然後跳轉到 work_draft.php
      Swal.fire("成功", j.msg, "success")
        .then(() => {
          // 跳轉到 work_draft.php（透過 hash 路由）
          window.location.hash = 'pages/work_draft.php';
        });
    } else {
      // 失敗時只顯示錯誤訊息
      Swal.fire("錯誤", j.msg, "error");
    }

  } catch (e) {
    Swal.fire("錯誤", e.message, "error");
  }
}

window.initWorkForm = function () {
  const formEl = document.querySelector("#work-main-form");
  if (!formEl) {
    window._workFormInitialized = false;
    return false;
  }
  
  // 檢查元素是否在當前 DOM 中（頁面切換時可能元素被移除又重新加入）
  const isInDOM = document.body.contains(formEl);
  if (!isInDOM) {
    window._workFormInitialized = false;
    return false;
  }
  
  // 如果已經初始化過，但元素仍然存在，先重置再重新初始化（處理頁面切換的情況）
  if (window._workFormInitialized) {
    window._workFormInitialized = false;
  }

  window._workFormInitialized = true;

  loadData();

  document.querySelector("#saveBtn")?.addEventListener("click", () => saveData("save"));
  document.querySelector("#submitBtn")?.addEventListener("click", () => saveData("submit"));
  return true;
};

// 自動初始化（類似 work_draft.js 的做法）
function tryInitWorkForm() {
  const formEl = document.querySelector("#work-main-form");
  if (formEl) {
    // 如果元素存在但初始化標記已設定，先重置（處理頁面切換的情況）
    if (window._workFormInitialized) {
      window._workFormInitialized = false;
    }
    initWorkForm();
    return true;
  } else {
    // 如果元素不存在，重置初始化標記
    window._workFormInitialized = false;
  }
  return false;
}

// 立即嘗試初始化（如果元素已存在）
if (!tryInitWorkForm()) {
  // 如果元素不存在，等待 DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      tryInitWorkForm();
    }, { once: true });
  } else {
    // DOM 已就緒但元素可能還沒載入（透過 AJAX 載入），延遲再試
    // 使用多層次的檢查機制，確保能捕捉到動態載入的內容
    let attempts = 0;
    const maxAttempts = 20; // 最多嘗試 20 次（約 2 秒）
    
    const checkInterval = setInterval(() => {
      attempts++;
      if (tryInitWorkForm() || attempts >= maxAttempts) {
        clearInterval(checkInterval);
      }
    }, 100);
    
    // 同時使用 MutationObserver 監聽 DOM 變化（更即時）
    const observer = new MutationObserver(() => {
      if (tryInitWorkForm()) {
        observer.disconnect();
        clearInterval(checkInterval);
      }
    });
    observer.observe(document.body || document.documentElement, {
      childList: true,
      subtree: true
    });
    
    // 10 秒後停止觀察和檢查（避免記憶體洩漏）
    setTimeout(() => {
      observer.disconnect();
      clearInterval(checkInterval);
    }, 10000);
  }
}
