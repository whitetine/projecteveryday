<?php
// pages/team_apply.php
session_start();

// 1. 權限檢查
if (!isset($_SESSION['u_ID']) || ($_SESSION['role_ID'] ?? 0) != 6) {
    echo "<script>alert('無權限，僅限學生訪問');location.href='../index.php';</script>";
    exit;
}

require_once __DIR__ . '/../includes/pdo.php';
$u_ID = $_SESSION['u_ID'];

// 2. 檢查是否已有「已結案且申請通過」的團隊
// 規則：只有 teamdata.team_status = 3 且 對應 teamapply.tap_status = 3 才視為「完全通過」，顯示提示卡片
$hasTeam = false;
$teamName = '';
try {
    // 兼容 team_u_ID 或 u_ID 欄位名稱
    $col = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'")->fetch() ? 'team_u_ID' : 'u_ID';

    // 找出學生目前所屬的最新一個 team（含 team_status），限制 tm_status = 1
    $sqlTeam = "SELECT t.team_ID, t.team_project_name, t.team_status
                FROM teamdata t
                JOIN teammember tm ON t.team_ID = tm.team_ID
                WHERE tm.$col = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                ORDER BY t.team_update_d DESC, t.team_ID DESC
                LIMIT 1";
    $stmt = $conn->prepare($sqlTeam);
    $stmt->execute([$u_ID]);
    $teamRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($teamRow) {
        $teamStatus = (int)($teamRow['team_status'] ?? 0);
        $teamNameCandidate = $teamRow['team_project_name'] ?? '';

        // 查詢該學生最新的 teamapply 狀態
        $tapStatus = null;
        try {
            $st2 = $conn->prepare("SELECT tap_status FROM teamapply WHERE tap_u_ID = ? ORDER BY tap_update_d DESC LIMIT 1");
            $st2->execute([$u_ID]);
            $tapRow = $st2->fetch(PDO::FETCH_ASSOC);
            if ($tapRow) {
                $tapStatus = (int)($tapRow['tap_status'] ?? 0);
            }
        } catch (Exception $e2) {
            $tapStatus = null;
        }

        if ($teamStatus === 3 && $tapStatus === 3) {
            $hasTeam = true;
            $teamName = $teamNameCandidate;
        }
    }
} catch (Exception $e) { /* 忽略錯誤 */ }

// 3. 獲取類組選項 (由 PHP 直接渲染，加快速度)
$groups = $conn->query("SELECT group_ID, group_name FROM groupdata WHERE group_status = 1")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php
// 判斷當前執行環境是否有 'pages' 資料夾結構
// 如果是在 pages 資料夾內執行 (直接開啟)，路徑要加 ../
// 如果是在根目錄被 include (透過 main.php)，路徑不用加
$path_prefix = (strpos($_SERVER['PHP_SELF'], '/pages/') !== false) ? '../' : '';
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>專題指導申請單</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="<?= $path_prefix ?>css/team_apply.css?v=<?= time() ?>">

    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php include "../head.php"; ?>

    <style>
        /* 防止 Vue 載入前看到 {{ }} */
        [v-cloak] {
            display: none !important;
        }

        .team-apply-container {
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
        }

        .required::after {
            content: " *";
            color: red;
        }

        .badge-member {
            font-size: 0.9em;
            padding: 8px 12px;
        }

        .cursor-pointer {
            cursor: pointer;
        }

        /* 唯讀模式下的輸入框樣式 */
        input:disabled,
        select:disabled,
        textarea:disabled {
            background-color: #f8f9fa;
            border-color: #e9ecef;
            cursor: not-allowed;
        }

        .img-preview {
            max-height: 200px;
            max-width: 100%;
            object-fit: contain;
            border: 1px solid #ddd;
            padding: 5px;
            border-radius: 5px;
        }
    </style>
</head>

<body>

    <div class="team-apply-container">

        <?php if ($hasTeam): ?>
            <div class="card shadow-sm text-center p-5">
                <div class="card-body">
                    <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
                    <h2 class="card-title">您已有專題組別</h2>
                    <p class="fs-4 mt-3">組別名稱：<strong><?= htmlspecialchars($teamName) ?></strong></p>
                    <div class="mt-4">
                        <a href="../main.php" class="btn btn-primary">返回首頁</a>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div id="teamApplyApp" v-cloak>
                <div class="card shadow">
                    <div class="card-header bg-primary text-white p-3">
                        <h4 class="mb-0"><i class="fas fa-file-alt me-2"></i>{{ applyConfig?.title || '專題指導申請單' }}</h4>
                    </div>

                    <div class="card-body p-4">
                        <div v-if="applyConfig && applyConfig.note" class="alert alert-light mb-3">
                            <strong>說明：</strong> {{ applyConfig.note }}
                        </div>
                        <div v-if="isReadonly" class="alert alert-info d-flex align-items-center mb-4">
                            <i class="fas fa-info-circle fa-2x me-3"></i>
                            <div>
                                <strong>申請狀態：</strong>
                                <span class="badge bg-warning text-dark" v-if="reviewData?.tap_status===1">審核中</span>
                                <span class="badge bg-success" v-if="reviewData?.tap_status===3">已通過</span>
                                <div class="small mt-1">目前表單已鎖定，如需修改請聯繫系辦或老師。</div>
                            </div>
                        </div>

                        <div v-if="reviewData && reviewData.tap_status === 4" class="alert alert-secondary mb-4">
                            <i class="fas fa-save me-2"></i><strong>暫存中</strong> — 可繼續編輯後提交申請。
                        </div>
                        <div v-if="reviewData && reviewData.tap_status === 2" class="alert alert-danger mb-4">
                            <h4><i class="fas fa-exclamation-triangle me-2"></i>申請已被退件</h4>
                            <hr>
                            <p class="mb-1"><strong>退件原因：</strong>{{ reviewData.tap_remark || '無詳細說明' }}</p>
                            <p class="small text-muted mb-0">請修正資料後重新提交。</p>
                        </div>

                        <form @submit.prevent="submitForm">

                            <div v-if="fieldShow('tap_name')" class="mb-3">
                                <label class="form-label" :class="{ required: fieldRequire('tap_name') }">專題名稱</label>
                                <input type="text" class="form-control" v-model="form.project_name" :disabled="isReadonly" placeholder="請輸入完整的專題題目" :required="fieldRequire('tap_name')">
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3" v-if="fieldShow('tap_teacher')">
                                    <label class="form-label" :class="{ required: fieldRequire('tap_teacher') }">指導老師</label>
                                    <select class="form-select" v-model="form.teacher_id" :disabled="isReadonly" :required="fieldRequire('tap_teacher')">
                                        <option value="" disabled>請選擇指導老師</option>
                                        <option v-for="t in teachers" :key="t.u_ID" :value="t.u_ID">
                                            {{ t.u_name }} (已帶組數：{{ t.led_count }} / 申請中：{{ t.apply_count }})
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3" v-if="fieldShow('tap_teacher_2')">
                                    <label class="form-label" :class="{ required: fieldRequire('tap_teacher_2') }">指導老師-2</label>
                                    <select class="form-select" v-model="form.teacher_id_2" :disabled="isReadonly" :required="fieldRequire('tap_teacher_2')">
                                        <option value="">無</option>
                                        <option v-for="t in teachers" :key="t.u_ID" :value="t.u_ID" :disabled="t.u_ID === form.teacher_id">
                                            {{ t.u_name }} (已帶組數：{{ t.led_count }} / 申請中：{{ t.apply_count }})
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3" v-if="fieldShow('tap_teacher_3')">
                                    <label class="form-label" :class="{ required: fieldRequire('tap_teacher_3') }">指導老師-3</label>
                                    <select class="form-select" v-model="form.teacher_id_3" :disabled="isReadonly" :required="fieldRequire('tap_teacher_3')">
                                        <option value="">無</option>
                                        <option v-for="t in teachers" :key="t.u_ID" :value="t.u_ID" :disabled="[form.teacher_id, form.teacher_id_2].includes(t.u_ID)">
                                            {{ t.u_name }} (已帶組數：{{ t.led_count }} / 申請中：{{ t.apply_count }})
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3" v-if="fieldShow('tap_group')">
                                    <label class="form-label" :class="{ required: fieldRequire('tap_group') }">類組</label>
                                    <select class="form-select" v-model="form.group_id" :disabled="isReadonly" :required="fieldRequire('tap_group')">
                                        <option value="" disabled>請選擇類組</option>
                                        <?php foreach ($groups as $g): ?>
                                            <option value="<?= $g['group_ID'] ?>"><?= htmlspecialchars($g['group_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3" v-if="fieldShow('tap_co_teacher')">
                                <label class="form-label">副指導老師 (選填)</label>
                                <select class="form-select" v-model="form.co_teacher_id" :disabled="isReadonly">
                                    <option value="">無</option>
                                    <option v-for="t in teachers" :key="t.u_ID" :value="t.u_ID" :disabled="t.u_ID === form.teacher_id">
                                        {{ t.u_name }} (已帶組數：{{ t.led_count }} / 申請中：{{ t.apply_count }})
                                    </option>
                                </select>
                            </div>

                            <div class="mb-3" v-if="fieldShow('tap_member')">
                                <label class="form-label" :class="{ required: fieldRequire('tap_member') }">組別成員 (含申請人最多{{ applyConfig?.max_member || 4 }}人)</label>

                                <div class="input-group mb-2" v-if="!isReadonly">
                                    <input type="text" class="form-control" v-model="memberInput" placeholder="請輸入學號 (申請人不用填)" @keyup.enter="addMember">
                                    <button class="btn btn-outline-primary" type="button" @click="addMember">
                                        <i class="fas fa-plus"></i> 新增
                                   </button>
                                </div>
                                <div class="form-text mb-2" v-if="!isReadonly">請輸入組員學號並點擊新增</div>

                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-primary badge-member rounded-pill">
                                        <i class="fas fa-user-circle me-1"></i> <?= $_SESSION['u_name'] ?? $u_ID ?> (目前使用者)
                                    </span>
                                    <span v-for="(m, index) in form.members" :key="m.u_ID" class="badge bg-light text-dark border badge-member rounded-pill d-flex align-items-center">
                                        {{ m.u_name }} ({{ m.u_ID }})
                                        <i v-if="!isReadonly" class="fas fa-times ms-2 text-danger cursor-pointer" @click="removeMember(index)" title="移除"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="mb-3" v-if="fieldShow('tap_url')">
                                <label class="form-label" :class="{ required: fieldRequire('tap_url') }">申請表照片 (需有老師簽名)</label>

                                <input type="file" id="apply_image" class="form-control mb-2" accept="image/*,.jpg,.jpeg,.png,.gif,.webp,.bmp,.tiff,.tif,.ico,.heic,.avif" @change="handleImageUpload" v-if="!isReadonly">

                                <div v-if="imagePreviewUrl" class="mt-2 position-relative d-inline-block">
                                    <img :src="imagePreviewUrl" class="img-preview">
                                    <button v-if="!isReadonly" type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" @click="removeImage">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                <div v-else class="text-muted small p-2 border border-dashed rounded text-center bg-light">
                                    尚未選擇圖片
                                </div>
                            </div>

                            <div class="mb-4" v-if="fieldShow('tap_des')">
                                <label class="form-label">備註說明</label>
                                <textarea class="form-control" rows="3" v-model="form.comment" :disabled="isReadonly" placeholder="有其他事項請在此說明..."></textarea>
                            </div>

                            <hr>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <a href="../index.php" class="btn btn-outline-secondary">登出</a>

                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <button type="button" class="btn btn-outline-info btn-sm" @click="downloadPDF" v-if="!isReadonly" title="下載後可列印簽名再上傳">
                                        <i class="fas fa-file-pdf"></i> 下載 PDF
                                    </button>
                                    <button type="button" class="btn btn-outline-info btn-sm" @click="downloadWord" v-if="!isReadonly" title="下載後可列印簽名再上傳">
                                        <i class="fas fa-file-word"></i> 下載 Word
                                    </button>
                                    <button type="button" class="btn btn-secondary" @click="resetForm" v-if="!isReadonly">
                                        <i class="fas fa-undo"></i> 重置
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" @click="saveDraft" :disabled="isSubmitting" v-if="!isReadonly">
                                        <i class="fas fa-save"></i> 暫存
                                    </button>
                                    <button type="submit" class="btn btn-primary px-4" :disabled="isSubmitting" v-if="!isReadonly">
                                        <span v-if="isSubmitting"><i class="fas fa-spinner fa-spin"></i> 處理中...</span>
                                        <span v-else><i class="fas fa-paper-plane"></i> 提交申請</span>
                                    </button>
                                </div>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
        window.TEAM_APPLY_CONFIG = {
            u_ID: '<?= htmlspecialchars($u_ID) ?>',
            u_name: '<?= htmlspecialchars($_SESSION['u_name'] ?? $u_ID) ?>',
            apiPath: API_ROOT
        };
    </script>
    <script src="<?= $path_prefix ?>js/team_apply.js?v=<?= time() ?>"></script>
    <!-- <script src="../js/team_apply.js"></script> -->
    <!-- <script src="js/team_apply.js?v=<?= time() ?>"></script> -->
</body>

</html>