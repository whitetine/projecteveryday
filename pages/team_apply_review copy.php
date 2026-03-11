<?php
// pages/team_apply_review.php  (純內容頁，給 main.php AJAX load 用)
if (session_status() === PHP_SESSION_NONE) session_start();

$role_ID = $_SESSION['role_ID'] ?? 0;
if (!in_array((int)$role_ID, [1,2], true)) {
  echo '<div class="alert alert-danger m-3">此頁面僅限主任/科辦使用</div>';
  return;
}
?>

<div class="review-container" id="reviewApp" data-page-id="team_apply_review">
  <div class="review-header">
    <h1 class="review-title">
      <i class="fa-solid fa-file-circle-check me-2"></i>專題指導申請單審核
    </h1>
    <!-- <div class="review-subtitle">主任 / 科辦 可審核學生的指導申請與名額限制</div> -->

    <div class="review-toolbar">
      <button class="btn btn-outline-secondary" id="btnRefresh">
        <i class="fa-solid fa-rotate"></i> 重新整理
      </button>

      <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
        <select class="form-select" style="min-width:220px;" v-model="selectedCohort" @change="loadApplications()">
          <option value="0">所有屆別（含已結束）</option>
          <option v-for="c in cohorts" :key="c.cohort_ID" :value="c.cohort_ID">
            {{ c.cohort_name }}（{{ c.year_label }}）
          </option>
        </select>

        <select class="form-select" style="min-width:140px;" v-model="filterStatus">
          <option value="all">全部</option>
          <option value="1">待審核</option>
          <option value="3">已通過</option>
          <option value="2">已退件</option>
        </select>

        <input class="form-control" style="min-width:260px;" placeholder="搜尋：團隊/老師/屆別/關鍵字..."
               v-model.trim="keyword" />
      </div>
    </div>
  </div>

  <div v-if="loading" class="review-loading">
    <div class="spinner"></div>
    <div class="mt-2">載入中...</div>
  </div>

  <div v-if="errorMsg" class="alert alert-danger mt-3">
    {{ errorMsg }}
  </div>

  <div v-if="!loading" class="review-list mt-3">
    <div v-if="filteredList.length === 0" class="empty-hint">
      目前沒有符合條件的申請。
    </div>

    <div v-for="a in filteredList" :key="a.tap_ID" class="apply-card" :class="{ readonly: a.is_readonly }">
      <div class="apply-main">
        <div class="apply-title">
          {{ a.project_name }}
          <span class="badge ms-2" :class="badgeClass(a.tap_status)">
            {{ statusText(a.tap_status) }}
          </span>
          <span v-if="a.is_readonly" class="badge bg-secondary ms-2">歷屆唯讀</span>
        </div>

        <div class="apply-meta">
          <span><i class="fa-solid fa-user-pen"></i> 申請者：{{ a.submitter_name }}（{{ a.tap_u_ID }}）</span>
          <span><i class="fa-solid fa-chalkboard-user"></i> 指導老師：{{ a.teacher_name }}</span>
          <span v-if="a.co_teacher_name"><i class="fa-solid fa-user-plus"></i> 副指導：{{ a.co_teacher_name }}</span>
          <span v-if="a.group_name"><i class="fa-solid fa-layer-group"></i> 類組：{{ a.group_name }}</span>
          <span><i class="fa-regular fa-clock"></i> 更新：{{ a.tap_update_d }}</span>
        </div>

        <div class="apply-members">
          <div class="small text-muted mb-1">成員（{{ a.approved_count }}/{{ a.total_count }}）</div>
          <div class="chips">
            <span class="chip" v-for="m in (a.members||[])" :key="m.u_ID">{{ m.u_name }}（{{ m.u_ID }}）</span>
          </div>
        </div>

        <div v-if="a.user_comment" class="apply-comment">
          <div class="small text-muted mb-1">學生備註</div>
          <div class="comment-box">{{ a.user_comment }}</div>
        </div>

        <div v-if="a.review_remark" class="apply-remark">
          <div class="small text-muted mb-1">審核備註</div>
          <div class="remark-box">{{ a.review_remark }}</div>
        </div>
      </div>

      <div class="apply-actions">
        <button class="btn btn-outline-primary w-100 mb-2" @click="openTeacherTeams(a.teacher_id, a.teacher_name)">
          <i class="fa-solid fa-users"></i> 查看老師帶隊
        </button>

        <button class="btn btn-outline-secondary w-100 mb-2" @click="openRemark(a)">
          <i class="fa-solid fa-pen"></i> 編輯備註
        </button>

        <a v-if="a.dcsub_url" class="btn btn-outline-dark w-100 mb-2" :href="resolveUrl(a.dcsub_url)" target="_blank">
          <i class="fa-solid fa-image"></i> 看申請照片
        </a>

        <button class="btn btn-success w-100 mb-2"
                :disabled="a.is_readonly || a.tap_status!=1"
                @click="approve(a)">
          <i class="fa-solid fa-check"></i> 通過
        </button>
        <button class="btn btn-danger w-100"
                :disabled="a.is_readonly || a.tap_status!=1"
                @click="reject(a)">
          <i class="fa-solid fa-xmark"></i> 退件
        </button>
      </div>
    </div>
  </div>

  <!-- 備註 Modal -->
  <div class="modal fade" id="remarkModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">審核備註</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2 text-muted small">（可先存備註，不改狀態）</div>
          <textarea class="form-control" rows="5" v-model="remarkDraft"></textarea>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
          <button class="btn btn-primary" @click="saveRemark()">儲存</button>
        </div>
      </div>
    </div>
  </div>

  <!-- 老師帶隊 Modal -->
  <div class="modal fade" id="teacherModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">老師帶隊清單：{{ teacherModal.name }}</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div v-if="teacherModal.loading" class="py-4 text-center">
            <div class="spinner"></div>
            <div class="mt-2">載入中...</div>
          </div>

          <div v-if="teacherModal.error" class="alert alert-danger">{{ teacherModal.error }}</div>

          <div v-if="!teacherModal.loading && teacherModal.teams.length===0" class="text-muted">
            目前沒有帶隊紀錄（或沒有 teammember/teamdata 對應）。
          </div>

          <div v-for="t in teacherModal.teams" :key="t.team_ID" class="team-card">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="fw-bold">{{ t.team_project_name }}</div>
                <div class="text-muted small">
                  屆別：{{ t.cohort_name || '-' }}　類組：{{ t.group_name || '-' }}　更新：{{ t.team_update_d }}
                </div>
              </div>
              <span class="badge bg-success" v-if="String(t.team_status)==='1'">進行中</span>
              <span class="badge bg-secondary" v-else>非進行</span>
            </div>

            <div class="mt-2">
              <div class="small text-muted">學生</div>
              <div class="chips">
                <span class="chip" v-for="s in (t.students||[])" :key="s.u_ID">{{ s.u_name }}（{{ s.u_ID }}）</span>
              </div>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">關閉</button>
        </div>
      </div>
    </div>
  </div>
</div>
