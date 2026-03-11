// /js/review/team_apply_review.teacherLimit.js
(() => {
  let allTeachersData = [];

  const api = () => window.REVIEW_API_PATH || "../api.php";

  function escapeHtml(text) {
    if (!text) return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  async function loadLimitTeachers() {
    const limitTeacherSelect = document.getElementById("limitTeacherSelect");
    if (!limitTeacherSelect) return;

    const res = await fetch(`${api()}?do=get_all_teachers`);
    const result = await res.json();

    if (result.ok && result.data) {
      allTeachersData = result.data;
      limitTeacherSelect.innerHTML = '<option value="">請選擇指導老師</option>';
      result.data.forEach(t => {
        const opt = document.createElement("option");
        opt.value = t.u_ID;
        opt.textContent = t.u_name;
        limitTeacherSelect.appendChild(opt);
      });
    }
  }

  async function loadLimitList() {
    const container = document.getElementById("limitListContainer");
    if (!container) return;

    try {
      const res = await fetch(`${api()}?do=get_teacher_team_limits`);
      const result = await res.json();

      if (!result.ok) throw new Error(result.msg || "載入限制列表失敗");

      // 申請中數量（你原本的邏輯照搬）
      const pendingRes = await fetch(`${api()}?do=get_pending_applications`);
      const pendingData = await pendingRes.json();
      const pendingApps = pendingData.ok ? (pendingData.applications || []) : [];

      const pendingCountMap = {};
      pendingApps.forEach(app => {
        const teacherId = app.teacher_id || app.tap_teacher;
        const cohortId  = app.cohort_ID || app.cohort_id;
        if (teacherId && cohortId) {
          const key = `${teacherId}_${cohortId}`;
          pendingCountMap[key] = (pendingCountMap[key] || 0) + 1;
        }
      });

      if (!result.data || result.data.length === 0) {
        container.innerHTML = '<div class="empty-list">目前沒有設定任何限制</div>';
        return;
      }

      container.innerHTML = `
        <div class="limit-table">
          <table class="table-clean">
            <thead>
              <tr>
                <th>屆別</th>
                <th>指導老師</th>
                <th>最大團隊數量</th>
                <th>目前帶的團隊</th>
                <th>申請中的團隊</th>
                <th>狀態</th>
              </tr>
            </thead>
            <tbody>
              ${result.data.map(limit => {
                const key = `${limit.ttl_u_ID}_${limit.cohort_ID}`;
                const pendingCount = pendingCountMap[key] || 0;
                return `
                  <tr>
                    <td>${escapeHtml(limit.cohort_name || "未設定")}</td>
                    <td>${escapeHtml(limit.u_name || limit.ttl_u_ID)}</td>
                    <td>${limit.max_count}</td>
                    <td>${limit.current_count || 0}</td>
                    <td>${pendingCount}</td>
                    <td><span class="status-badge ${limit.status_class || "status-available"}">${escapeHtml(limit.status || "可帶")}</span></td>
                  </tr>
                `;
              }).join("")}
            </tbody>
          </table>
        </div>
      `;
    } catch (e) {
      console.error(e);
      container.innerHTML = '<div class="error-text">載入失敗</div>';
    }
  }

  function initTeacherLimitFeature() {
    const setLimitBtn = document.getElementById("setTeacherLimitBtn");
    const overlay    = document.getElementById("teacherLimitModalOverlay");
    const closeBtn   = document.getElementById("teacherLimitModalClose");
    const cancelBtn  = document.getElementById("cancelLimitBtn");
    const saveBtn    = document.getElementById("saveLimitBtn");

    const limitTeacherSelect = document.getElementById("limitTeacherSelect");
    const limitCohortSelect  = document.getElementById("limitCohortSelect");
    const limitMaxCount      = document.getElementById("limitMaxCount");
    const cohortWrapper      = document.getElementById("cohortSelectWrapper");

    if (!setLimitBtn || !overlay) return;

    const closeModal = () => {
      overlay.style.display = "none";
      overlay.classList.remove("active");
      if (limitTeacherSelect) limitTeacherSelect.value = "";
      if (limitCohortSelect) limitCohortSelect.value = "";
      if (limitMaxCount) limitMaxCount.value = "0";
      if (cohortWrapper) cohortWrapper.style.display = "none";
    };

    setLimitBtn.addEventListener("click", async (e) => {
      e.preventDefault();
      e.stopPropagation();
      overlay.style.display = "flex";
      overlay.classList.add("active");
      await loadLimitTeachers();
      await loadLimitList();
    });

    closeBtn?.addEventListener("click", closeModal);
    cancelBtn?.addEventListener("click", closeModal);

    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closeModal();
    });

    limitTeacherSelect?.addEventListener("change", (e) => {
      const teacherId = e.target.value;
      if (!teacherId) {
        cohortWrapper.style.display = "none";
        limitCohortSelect.value = "";
        return;
      }

      const teacher = allTeachersData.find(t => t.u_ID === teacherId);
      if (!teacher?.cohorts?.length) {
        cohortWrapper.style.display = "none";
        limitCohortSelect.value = "";
        Swal.fire({ icon: "info", title: "提示", text: "該老師在進行中的屆別中沒有擔任指導老師" });
        return;
      }

      limitCohortSelect.innerHTML = '<option value="">請選擇屆別</option>';
      teacher.cohorts.forEach(c => {
        const opt = document.createElement("option");
        opt.value = c.cohort_ID;
        opt.textContent = c.cohort_name;
        limitCohortSelect.appendChild(opt);
      });

      cohortWrapper.style.display = "flex";
      limitCohortSelect.value = teacher.cohorts.length === 1 ? teacher.cohorts[0].cohort_ID : "";
    });

    saveBtn?.addEventListener("click", async () => {
      const teacherId = limitTeacherSelect.value;
      const cohortId  = limitCohortSelect.value;
      const maxCount  = parseInt(limitMaxCount.value, 10) || 0;

      if (!teacherId) return Swal.fire({ icon:"warning", title:"請選擇指導老師" });
      if (!cohortId)  return Swal.fire({ icon:"warning", title:"請選擇屆別" });
      if (maxCount < 0) return Swal.fire({ icon:"warning", title:"團隊數量不能為負數" });

      const fd = new FormData();
      fd.append("teacher_id", teacherId);
      fd.append("cohort_ID", cohortId);
      fd.append("max_count", maxCount);

      const res = await fetch(`${api()}?do=set_teacher_team_limit`, { method:"POST", body: fd });
      const result = await res.json();

      if (result.ok) {
        await Swal.fire({ icon:"success", title:"設定成功", timer: 1200, showConfirmButton:false });
        limitTeacherSelect.value = "";
        limitMaxCount.value = "0";
        cohortWrapper.style.display = "none";
        await loadLimitList();
      } else {
        Swal.fire({ icon:"error", title:"設定失敗", text: result.msg || "未知錯誤" });
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initTeacherLimitFeature);
  } else {
    initTeacherLimitFeature();
  }
})();
