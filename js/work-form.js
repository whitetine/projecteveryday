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

    Swal.fire(j.success ? "成功" : "錯誤", j.msg, j.success ? "success" : "error")
      .then(() => j.reload && loadData());

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
  if (window._workFormInitialized) return true;

  window._workFormInitialized = true;

  loadData();

  document.querySelector("#saveBtn")?.addEventListener("click", () => saveData("save"));
  document.querySelector("#submitBtn")?.addEventListener("click", () => saveData("submit"));
  return true;
};
