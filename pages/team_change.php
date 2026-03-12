 <?php
// pages/team_change.php - 組別異動紀錄中心（Audit Log UI）
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/pdo.php';

if (!isset($_SESSION['u_ID'])) {
    echo '<div class="alert alert-danger m-3">請先登入</div>';
    exit;
}

$u_ID = $_SESSION['u_ID'];
$role_ID = (int)($_SESSION['role_ID'] ?? 0);

if (!in_array($role_ID, [1, 2, 4, 6])) {
    echo '<div class="alert alert-warning m-3">此頁面僅限科辦、主任、指導老師、學生使用</div>';
    exit;
}

$isStudent = ($role_ID === 6);
$isOffice = ($role_ID === 2);
$isOfficeOrDirector = in_array($role_ID, [1, 2]); // 主任(1)、科辦(2) 可編輯異動

$myTeam = null;
if ($isStudent) {
    $tmCol = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->fetch() ? 'team_u_ID' : 'u_ID';
    $stmt = $conn->prepare("SELECT tm.team_ID, t.team_project_name, t.cohort_ID 
        FROM teammember tm 
        JOIN teamdata t ON tm.team_ID = t.team_ID 
        WHERE tm.$tmCol = ? AND t.team_status = 1 AND (tm.tm_status IS NULL OR tm.tm_status = 1) 
        LIMIT 1");
    $stmt->execute([$u_ID]);
    $myTeam = $stmt->fetch(PDO::FETCH_ASSOC);
}

$path_prefix = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false) ? '../' : '';
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$is_ajax) {
    ?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>組別異動紀錄</title>
    <?php include __DIR__ . '/../head.php'; ?>
</head>
<body>
<?php } ?>
<div class="changelog-page changelog-page-title-top" id="changelogApp">
    <?php if ($isStudent && !$myTeam): ?>
        <div class="alert alert-warning m-3">
            <i class="fa-solid fa-info-circle me-2"></i>您目前尚未加入任何組別。
        </div>
    <?php else: ?>

    <div class="changelog-topbar">
        <div class="title">
            <h1>組別異動紀錄</h1>
            <p>追蹤組名、指導老師、成員異動</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <template v-if="isOfficeOrDirector && cohorts.length">
                <select v-model="filters.cohort_ID" @change="onCohortChange" class="form-select form-select-sm" style="max-width:180px; border-radius:999px;">
                    <option value="">全部屆別</option>
                    <option v-for="c in cohorts" :key="c.cohort_ID" :value="c.cohort_ID">{{ c.cohort_name || c.year_label || c.cohort_ID }}</option>
                </select>
            </template>
            <template v-else-if="isOffice">
                <button class="btn btn-primary" type="button" @click="openCreateFormModal">
                    <i class="fa-solid fa-plus me-1"></i>新增申請單
                </button>
            </template>
            <template v-else-if="isStudent && team_ID">
                <div class="dropdown">
                    <button id="applyChangeDropdownBtn" class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">新增申請</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li v-for="f in availableChangeForms" :key="f.tcf_ID + '-' + f.tcf_change_type">
                            <a class="dropdown-item" href="javascript:void(0)" @click="openApplyModal(f.tcf_change_type, f.tcf_name, f.tcf_ID)">{{ f.tcf_name }}</a>
                        </li>
                        <li v-if="!availableChangeForms.length" class="dropdown-item text-muted">目前無開放中的申請單</li>
                    </ul>
                </div>
            </template>
        </div>
    </div>

    <div class="changelog-wrap">
        <div v-if="!isStudent" class="changelog-filters">
            <div v-if="isOfficeOrDirector" class="changelog-field">
                <label>屆別</label>
                <select v-model="filters.cohort_ID" @change="onCohortChange" class="changelog-select">
                    <option value="">全部</option>
                    <option v-for="c in cohorts" :key="c.cohort_ID" :value="c.cohort_ID">{{ c.cohort_name || c.year_label || c.cohort_ID }}</option>
                </select>
            </div>
            <div class="changelog-field">
                <label>異動類型</label>
                <select v-model="filters.change_type" @change="loadData" class="changelog-select">
                    <option value="">全部</option>
                    <option value="TEAM_RENAME">組名變更</option>
                    <option value="TEACHER_CHANGE">指導老師變更</option>
                    <option value="MEMBER_ADD">成員新增</option>
                    <option value="MEMBER_REMOVE">成員移除</option>
                    <option value="MEMBER_CHANGE">成員異動</option>
                </select>
            </div>
            <div class="changelog-field">
                <label>搜尋</label>
                <input v-model="filters.team_search" @input="debounceLoad" @keydown.enter="loadData" class="changelog-input" placeholder="搜尋 組別 / 建立者 / 老師 / 組名" />
            </div>
            <button @click="loadData" class="changelog-btn" type="button">搜尋</button>
        </div>

        <!-- 系辦/主任：開放中的申請單、申請單要填什麼、類型說明 -->
        <div v-if="isOfficeOrDirector" class="changelog-office-info mb-4">
            <div class="changelog-card mb-3">
                <div class="changelog-card-hd">
                    <span class="fw-bold">開放中的申請單</span>
                    <small class="text-muted ms-2">（依上方屆別篩選）</small>
                </div>
                <div class="changelog-tableWrap">
                    <table class="changelog-table" v-if="officeChangeForms.length">
                        <thead>
                            <tr>
                                <th>屆別</th>
                                <th>申請單名稱</th>
                                <th>類型</th>
                                <th>開放期間</th>
                                <th>狀態</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="f in officeChangeForms" :key="f.tcf_ID">
                                <td>{{ f.cohort_label || f.tcf_cohort_ID }}</td>
                                <td>{{ f.tcf_name }}</td>
                                <td>{{ f.type_label }}</td>
                                <td>{{ formatFormPeriod(f.tcf_open_d, f.tcf_close_d) }}</td>
                                <td><span class="badge bg-success">啟用</span></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" @click.stop="openEditFormModal(f)" title="編輯申請單">編輯</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="mb-0 p-3 text-muted">目前沒有開放中的申請單。請點選右上角「新增申請單」建立。</p>
                </div>
            </div>
            <div class="changelog-card">
                <div class="changelog-card-hd fw-bold">申請單要填什麼／類型說明</div>
                <div class="changelog-card-bd small">
                    <p class="mb-2"><strong>類型說明：</strong></p>
                    <ul class="mb-3">
                        <li><strong>組名變更</strong>：專題題目（組名）變更時使用。</li>
                        <li><strong>指導老師變更</strong>：更換組別指導老師時使用。</li>
                        <li><strong>成員新增</strong>：新增組員時使用。</li>
                        <li><strong>成員移除</strong>：組員退組時使用。</li>
                        <li><strong>成員異動</strong>：同時涉及新增/移除等成員變更時使用。</li>
                    </ul>
                    <p class="mb-2"><strong>各類型需填寫內容：</strong></p>
                    <ul class="mb-0">
                        <li>組名變更：新專題題目、變更原因（選填）、附件（選填）。</li>
                        <li>指導老師變更：新指導老師、變更原因（選填）、附件（選填）。</li>
                        <li>成員新增／移除／異動：異動成員、變更原因（選填）、附件（選填）。</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 學生：申請單要填什麼／類型說明（字體較大） -->
        <div v-if="isStudent" class="changelog-office-info mb-4">
            <div class="changelog-card changelog-card-student-desc">
                <div class="changelog-card-hd fw-bold">申請單要填什麼／類型說明</div>
                <div class="changelog-card-bd changelog-student-desc-bd">
                    <p class="mb-2"><strong>類型說明：</strong></p>
                    <ul class="mb-3">
                        <li><strong>組名變更</strong>：專題題目（組名）變更時使用。</li>
                        <li><strong>指導老師變更</strong>：更換組別指導老師時使用。</li>
                        <li><strong>成員新增</strong>：新增組員時使用。</li>
                        <li><strong>成員移除</strong>：組員退組時使用。</li>
                        <li><strong>成員異動</strong>：同時涉及新增/移除等成員變更時使用。</li>
                    </ul>
                    <p class="mb-2"><strong>各類型需填寫內容：</strong></p>
                    <ul class="mb-0">
                        <li>組名變更：新專題題目、變更原因（選填）、附件（選填）。</li>
                        <li>指導老師變更：新指導老師、變更原因（選填）、附件（選填）。</li>
                        <li>成員新增／移除／異動：異動成員、變更原因（選填）、附件（選填）。</li>
                    </ul>
                </div>
            </div>
        </div>

        <div v-if="!isStudent && stats" class="changelog-stats">
            <div class="changelog-stat">
                <div class="k">組名變更</div>
                <div class="v changelog-mono">{{ stats.TEAM_RENAME || 0 }}</div>
            </div>
            <div class="changelog-stat">
                <div class="k">指導老師變更</div>
                <div class="v changelog-mono">{{ stats.TEACHER_CHANGE || 0 }}</div>
            </div>
            <div class="changelog-stat">
                <div class="k">成員新增</div>
                <div class="v changelog-mono">{{ stats.MEMBER_ADD || 0 }}</div>
            </div>
            <div class="changelog-stat">
                <div class="k">審核中</div>
                <div class="v changelog-mono">{{ stats.PENDING || 0 }}</div>
            </div>
        </div>

        <div v-if="isStudent && changes.length" class="changelog-stats">
            <div class="changelog-stat">
                <div class="k">組名變更</div>
                <div class="v changelog-mono">{{ computedStats.TEAM_RENAME }}</div>
            </div>
            <div class="changelog-stat">
                <div class="k">指導老師變更</div>
                <div class="v changelog-mono">{{ computedStats.TEACHER_CHANGE }}</div>
            </div>
            <div class="changelog-stat">
                <div class="k">成員新增</div>
                <div class="v changelog-mono">{{ computedStats.MEMBER_ADD }}</div>
            </div>
            <div class="changelog-stat">
                <div class="k">審核中</div>
                <div class="v changelog-mono">{{ computedStats.PENDING }}</div>
            </div>
        </div>

        <div v-if="loading" class="text-center py-5">
            <i class="fa-solid fa-spinner fa-spin fa-2x text-secondary"></i>
            <p class="mt-2 text-muted">載入中...</p>
        </div>

        <div v-else class="changelog-grid">
            <div class="changelog-card">
                <div class="changelog-tableWrap">
                    <table class="changelog-table">
                        <thead>
                            <tr>
                                <th>類型</th>
                                <th>組別 / 內容</th>
                                <th>建立者</th>
                                <th>時間</th>
                                <th>狀態</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in paginatedChanges" :key="c.tc_ID" @click="openDetail(c)">
                                <td>
                                    <span class="changelog-typeTag">
                                        <span class="changelog-dot" :style="{background: typeDotColor(c.change_type)}"></span>
                                        {{ typeLabel(c.change_type) }}
                                    </span>
                                </td>
                                <td>{{ buildSummary(c) }}</td>
                                <td>{{ c.tc_created_name || c.tc_created_u_ID || '-' }}</td>
                                <td>{{ c.tc_created_d || '-' }}</td>
                                <td><span :class="['changelog-status', 'changelog-s' + (c.tc_status || 1)]">{{ statusLabel(c.tc_status) }}</span></td>
                                <td>
                                    <div class="changelog-actions">
                                        <button class="changelog-linkbtn" @click="openDetail(c)">查看</button>
                                        <button v-if="isStudent && c.tc_status == 0" class="changelog-linkbtn" @click.stop="openReapplyModal(c)">重新申請</button>
                                        <template v-if="isOfficeOrDirector">
                                            <button class="changelog-linkbtn" @click.stop="openEditModal(c)">編輯</button>
                                            <template v-if="isOfficeOrDirector && c.tc_status == 1">
                                                <div class="changelog-actionbtns">
                                                    <button class="changelog-btn-pass" @click.stop="updateStatus(c.tc_ID, 3)">通過</button>
                                                    <button class="changelog-btn-reject" @click.stop="updateStatus(c.tc_ID, 0)">退件</button>
                                                </div>
                                            </template>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!paginatedChanges.length">
                                <td colspan="6" class="text-center py-4 text-muted">沒有符合條件的紀錄。</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="totalPages > 1" class="changelog-pager">
                    <button v-if="page > 1" class="changelog-pagebtn" @click="page--">‹</button>
                    <button v-for="p in pageNumbers" :key="p" :class="['changelog-pagebtn', {active: p===page}]" @click="page=p">{{ p }}</button>
                    <button v-if="page < totalPages" class="changelog-pagebtn" @click="page++">›</button>
                </div>
            </div>

            <div class="changelog-drawer">
                <div class="changelog-drawerHead">
                    <h3>{{ detail ? '異動詳細 #' + detail.tc_ID : '異動詳細' }}</h3>
                    <button class="x" type="button" @click="closeDetail">✕</button>
                </div>
                <div v-if="detail" class="changelog-kv">
                    <b>屆別</b><div>{{ detail.cohort_name || detail.tc_cohort || '—' }}</div>
                    <b>組別</b><div>{{ detail.team_project_name || '—' }}</div>
                    <b>異動類型</b><div>{{ typeLabel(detail.change_type) }}</div>
                    <b>建立者</b><div>{{ detail.tc_created_name || detail.tc_created_u_ID }}</div>
                    <b>建立時間</b><div>{{ detail.tc_created_d }}</div>
                    <b>狀態</b><div><span :class="['changelog-status', 'changelog-s' + (detail.tc_status || 1)]">{{ statusLabel(detail.tc_status) }}</span></div>
                </div>
                <div v-else class="changelog-kv">
                    <b>屆別</b><div>—</div>
                    <b>組別</b><div>—</div>
                    <b>異動類型</b><div>—</div>
                    <b>建立者</b><div>—</div>
                    <b>建立時間</b><div>—</div>
                    <b>狀態</b><div>—</div>
                </div>
                <div class="changelog-section">
                    <h4>變更內容</h4>
                    <div v-if="detail" class="changelog-diff">
                        <template v-if="detail.change_type === 'TEAM_RENAME'">
                            <div>原組名：<s>{{ detail.tc_team_name_old || '—' }}</s></div>
                            <div>新組名：<strong>{{ detail.tc_team_name_new || '—' }}</strong></div>
                        </template>
                        <template v-else-if="detail.change_type === 'TEACHER_CHANGE'">
                            <div>原指導老師：<s>{{ detail.tc_teacher_old || '—' }}</s></div>
                            <div>新指導老師：<strong>{{ detail.tc_teacher_new || '—' }}</strong></div>
                        </template>
                        <template v-else-if="detail.change_type === 'MEMBER_ADD' || detail.change_type === 'MEMBER_REMOVE' || detail.change_type === 'MEMBER_CHANGE'">
                            <div>成員異動：<strong>{{ detail.tc_member_display || detail.tc_member || '—' }}</strong></div>
                        </template>
                        <div v-else>—</div>
                    </div>
                    <div v-else class="changelog-diff text-muted">點選左側「查看」以顯示內容。</div>
                </div>
                <div class="changelog-section">
                    <h4>備註（變更原因）</h4>
                    <div class="changelog-note">{{ detail && detail.tc_reason ? detail.tc_reason : '—' }}</div>
                </div>
                <div v-if="detail && detail.tc_attachment" class="changelog-section">
                    <h4>附件圖片</h4>
                    <img :src="getAttachmentUrl(detail.tc_attachment)" alt="附件" class="img-thumbnail" style="max-height:200px;max-width:100%;">
                </div>
                <div v-if="detail && (detail.tc_status === 0 || detail.tc_status === 3)" class="changelog-section">
                    <h4>審核人備註</h4>
                    <div class="changelog-note">{{ detail.tc_u_reason ? detail.tc_u_reason : '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 編輯異動 Modal（科辦、主任） -->
    <div class="modal fade" id="editChangelogModal" tabindex="-1" aria-labelledby="editChangelogModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editChangelogModalLabel">編輯異動 #{{ editTarget ? editTarget.tc_ID : '' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editChangelogForm" @submit.prevent="saveEdit">
                        <div class="mb-3">
                            <label class="form-label fw-bold">狀態</label>
                            <select v-model="editForm.status" class="form-select" required>
                                <option value="0">退件</option>
                                <option value="1">申請</option>
                                <option value="2">等待老師簽名</option>
                                <option value="3">通過</option>
                                <option value="4">暫存</option>
                            </select>
                            <small class="text-muted">改為「通過」時，系統會套用異動至組別；改為「退件」則不套用。</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">審核人備註</label>
                            <textarea v-model="editForm.tc_u_reason" class="form-control" rows="3" placeholder="可填寫通過或退件原因（選填）" maxlength="300"></textarea>
                            <small class="text-muted">最多 300 字</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" @click="saveEdit" :disabled="editSubmitting">
                        <span v-if="editSubmitting"><span class="spinner-border spinner-border-sm me-1"></span>儲存中...</span>
                        <span v-else>儲存</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 重新申請 Modal（學生，退件後編輯再提交） -->
    <div class="modal fade" id="reapplyModal" tabindex="-1" aria-labelledby="reapplyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reapplyModalLabel">重新申請 #{{ reapplyTarget ? reapplyTarget.tc_ID : '' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" v-if="reapplyTarget">
                    <form id="reapplyForm" @submit.prevent="submitReapply">
                        <div class="mb-3">
                            <label class="form-label fw-bold">{{ reapplyTarget.change_type === 'TEAM_RENAME' ? '原專題題目' : '專題題目' }}</label>
                            <input type="text" class="form-control" :value="formData.team_project_name" readonly disabled>
                        </div>
                        <div v-if="reapplyTarget.change_type === 'TEAM_RENAME'" class="mb-3">
                            <label class="form-label fw-bold">新專題題目 <span class="text-danger">*</span></label>
                            <input type="text" v-model="reapplyForm.tc_team_name_new" class="form-control" placeholder="請輸入新專題題目" required>
                        </div>
                        <div v-if="reapplyTarget.change_type === 'TEACHER_CHANGE'" class="mb-3">
                            <label class="form-label fw-bold">原指導老師</label>
                            <input type="text" class="form-control" :value="reapplyTarget.tc_teacher_old || '—'" readonly disabled>
                        </div>
                        <div v-if="reapplyTarget.change_type === 'TEACHER_CHANGE'" class="mb-3">
                            <label class="form-label fw-bold">新指導老師 <span class="text-danger">*</span></label>
                            <select v-model="reapplyForm.tc_teacher_new" class="form-select" required>
                                <option value="">請選擇</option>
                                <option v-for="t in (formData.teachers || [])" :key="t.u_ID" :value="t.u_ID">{{ t.u_name }}</option>
                            </select>
                        </div>
                        <div v-if="reapplyTarget.change_type === 'MEMBER_ADD' || reapplyTarget.change_type === 'MEMBER_CHANGE'" class="mb-3">
                            <label class="form-label fw-bold">新增組員 <span class="text-danger">*</span></label>
                            <select v-model="reapplyForm.tc_member" class="form-select" required>
                                <option value="">請選擇</option>
                                <option v-for="s in availableStudents" :key="s.u_ID" :value="s.u_ID">{{ s.u_name }} ({{ s.u_ID }})</option>
                            </select>
                        </div>
                        <div v-if="reapplyTarget.change_type === 'MEMBER_REMOVE'" class="mb-3">
                            <label class="form-label fw-bold">退出組員 <span class="text-danger">*</span></label>
                            <select v-model="reapplyForm.tc_member" class="form-select" required>
                                <option value="">請選擇</option>
                                <option v-for="m in formData.members" :key="m.u_ID" :value="m.u_ID">{{ m.u_name }} ({{ m.u_ID }})</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">變更原因</label>
                            <textarea v-model="reapplyForm.reason" class="form-control" rows="3" placeholder="請說明變更原因（選填）"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" form="reapplyForm" class="btn btn-primary" :disabled="reapplySubmitting">
                        <span v-if="reapplySubmitting"><span class="spinner-border spinner-border-sm me-1"></span>送出中...</span>
                        <span v-else>送出申請</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 系辦：新增申請單 Modal -->
    <div class="modal fade" id="createFormModal" tabindex="-1" aria-labelledby="createFormModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createFormModalLabel">新增申請單</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="createFormForm" @submit.prevent="submitCreateForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold">申請單名稱</label>
                            <input type="text" v-model="createForm.tcf_name" class="form-control" placeholder="留空則使用預設名稱">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">屆別 <span class="text-danger">*</span></label>
                            <select v-model="createForm.tcf_cohort_ID" class="form-select" required>
                                <option value="">請選擇</option>
                                <option v-for="c in formCohorts" :key="c.cohort_ID" :value="c.cohort_ID">{{ c.cohort_name || c.year_label || c.cohort_ID }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">異動類型 <span class="text-danger">*</span></label>
                            <select v-model="createForm.tcf_change_type" class="form-select" required>
                                <option value="">請選擇</option>
                                <option value="TEAM_RENAME">組名變更</option>
                                <option value="TEACHER_CHANGE">指導老師變更</option>
                                <option value="MEMBER_ADD">成員新增</option>
                                <option value="MEMBER_REMOVE">成員移除</option>
                                <option value="MEMBER_CHANGE">成員異動</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">開放填寫時間</label>
                            <input type="datetime-local" v-model="createForm.tcf_open_d" class="form-control">
                            <small class="text-muted">留空表示立即開放</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">截止時間</label>
                            <input type="datetime-local" v-model="createForm.tcf_close_d" class="form-control">
                            <small class="text-muted">留空表示無截止</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" form="createFormForm" class="btn btn-primary" :disabled="createFormSubmitting">
                        <span v-if="createFormSubmitting"><span class="spinner-border spinner-border-sm me-1"></span>建立中...</span>
                        <span v-else>建立</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 系辦：編輯申請單 Modal -->
    <div class="modal fade" id="editFormModal" tabindex="-1" aria-labelledby="editFormModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editFormModalLabel">編輯申請單</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" v-if="editFormTarget">
                    <form id="editFormForm" @submit.prevent="submitEditForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold">申請單名稱</label>
                            <input type="text" v-model="editFormName" class="form-control" placeholder="留空則使用預設名稱">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">屆別</label>
                            <input type="text" class="form-control" :value="editFormTarget.cohort_label || editFormTarget.tcf_cohort_ID" readonly disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">類型</label>
                            <input type="text" class="form-control" :value="editFormTarget.type_label" readonly disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">開放填寫時間</label>
                            <input type="datetime-local" v-model="editFormOpenD" class="form-control">
                            <small class="text-muted">留空表示立即開放</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">截止時間</label>
                            <input type="datetime-local" v-model="editFormCloseD" class="form-control">
                            <small class="text-muted">留空表示無截止</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" form="editFormForm" class="btn btn-primary" :disabled="editFormSubmitting">
                        <span v-if="editFormSubmitting"><span class="spinner-border spinner-border-sm me-1"></span>儲存中...</span>
                        <span v-else>儲存</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 變更申請 Modal -->
    <div class="modal fade" id="applyModal" tabindex="-1" aria-labelledby="applyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="applyModalLabel">{{ applyFormTitle }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="applyForm" @submit.prevent="submitApply">
                        <div class="mb-3">
                            <label class="form-label fw-bold">{{ applyType === 'TEAM_RENAME' ? '原專題題目' : '專題題目' }}</label>
                            <input type="text" class="form-control" :value="formData.team_project_name" readonly disabled>
                        </div>

                        <div v-if="applyType === 'TEAM_RENAME'" class="mb-3">
                            <label class="form-label fw-bold">新專題題目 <span class="text-danger">*</span></label>
                            <input type="text" v-model="applyForm.tc_team_name_new" class="form-control" placeholder="請輸入新專題題目" required>
                        </div>

                        <div v-if="applyType === 'TEACHER_CHANGE'" class="mb-3">
                            <label class="form-label fw-bold">原指導老師</label>
                            <input type="text" class="form-control" :value="formData.teacher ? formData.teacher.u_name : '—'" readonly disabled>
                        </div>
                        <div v-if="applyType === 'TEACHER_CHANGE'" class="mb-3">
                            <label class="form-label fw-bold">新指導老師 <span class="text-danger">*</span></label>
                            <select v-model="applyForm.tc_teacher_new" class="form-select" required>
                                <option value="">請選擇</option>
                                <option v-for="t in (formData.teachers || []).filter(t => t.u_ID !== (formData.teacher && formData.teacher.u_ID))" :key="t.u_ID" :value="t.u_ID">{{ t.u_name }}</option>
                            </select>
                            <div v-if="(!formData.teachers || formData.teachers.length <= 1) && formData.teacher" class="text-muted small mt-1">若無其他指導老師可選，請聯繫科辦</div>
                        </div>

                        <div v-if="applyType === 'MEMBER_ADD' || applyType === 'MEMBER_CHANGE'" class="mb-3">
                            <label class="form-label fw-bold">原組員</label>
                            <div class="text-muted small">{{ (formData.members || []).map(m => m.u_name).join('、') || '—' }}</div>
                        </div>
                        <div v-if="applyType === 'MEMBER_ADD' || applyType === 'MEMBER_CHANGE'" class="mb-3">
                            <label class="form-label fw-bold">新增組員 <span class="text-danger">*</span></label>
                            <select v-model="applyForm.tc_member" class="form-select" required>
                                <option value="">請選擇</option>
                                <option v-for="s in availableStudents" :key="s.u_ID" :value="s.u_ID">{{ s.u_name }} ({{ s.u_ID }})</option>
                            </select>
                            <div v-if="(applyType === 'MEMBER_ADD' || applyType === 'MEMBER_CHANGE') && availableStudents.length === 0" class="text-muted small mt-1">目前無可新增的組員（同屆尚未加入組別的學生）</div>
                        </div>

                        <div v-if="applyType === 'MEMBER_REMOVE'" class="mb-3">
                            <label class="form-label fw-bold">原組員</label>
                            <div class="text-muted small">{{ (formData.members || []).map(m => m.u_name).join('、') || '—' }}</div>
                        </div>
                        <div v-if="applyType === 'MEMBER_REMOVE'" class="mb-3">
                            <label class="form-label fw-bold">退出組員 <span class="text-danger">*</span></label>
                            <select v-model="applyForm.tc_member" class="form-select" required>
                                <option value="">請選擇</option>
                                <option v-for="m in formData.members" :key="m.u_ID" :value="m.u_ID">{{ m.u_name }} ({{ m.u_ID }})</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">{{ applyType === 'MEMBER_ADD' || applyType === 'MEMBER_CHANGE' ? '新增原因' : applyType === 'MEMBER_REMOVE' ? '退出原因' : '變更原因' }}</label>
                            <textarea v-model="applyForm.reason" class="form-control" rows="3" placeholder="請說明變更原因（選填）"></textarea>
                        </div>
                        <!-- 圖片上傳改為之後在列表中批次繳交，這裡先不顯示上傳區塊 -->
                    </form>
                </div>
                <div class="modal-footer d-flex justify-content-end flex-wrap gap-2">
                    <button type="button" class="btn btn-success" @click="saveAndShowDownload" :disabled="applySubmitting">
                        <i class="fa-solid fa-save me-1"></i>暫存並下載
                    </button>
                    <template v-if="showDownloadButtons">
                        <button type="button" class="btn btn-outline-danger" @click="downloadFormPDF" :disabled="applySubmitting">
                            <i class="fa-solid fa-file-pdf me-1"></i>下載 PDF
                        </button>
                        <button type="button" class="btn btn-outline-primary" @click="downloadFormWord" :disabled="applySubmitting">
                            <i class="fa-solid fa-file-word me-1"></i>下載 Word
                        </button>
                        <button type="button" class="btn btn-outline-secondary" @click="printForm">
                            <i class="fa-solid fa-print me-1"></i>列印
                        </button>
                    </template>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
(function() {
    if (typeof initTeamChange !== 'function') return;
    initTeamChange({
        isStudent: <?= $isStudent ? 'true' : 'false' ?>,
        isOffice: <?= $isOffice ? 'true' : 'false' ?>,
        isOfficeOrDirector: <?= $isOfficeOrDirector ? 'true' : 'false' ?>,
        team_ID: <?= (int)(($myTeam ?? [])['team_ID'] ?? 0) ?>,
        teamName: <?= json_encode(($myTeam ?? [])['team_project_name'] ?? '') ?>,
        teacherCohort_ID: <?= ($role_ID === 4 && !empty($_SESSION['cohort_ID'])) ? (int)$_SESSION['cohort_ID'] : 'null' ?>
    });
})();
</script>
<?php if (!$is_ajax): ?>
</body>
</html>
<?php endif; ?>
