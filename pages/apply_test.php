<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/../includes/pdo.php';
date_default_timezone_set('Asia/Taipei');

// 僅在 POST 上傳簽名前 PDF 時走 API（sub_ID 可為 0，由 upload 腳本依 doc_ID+u_ID 解析）
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (int) ($_POST['doc_ID'] ?? 0) > 0
    && isset($_FILES['original_pdf']) && $_FILES['original_pdf']['error'] === UPLOAD_ERR_OK
) {
    require __DIR__ . '/apply_test_upload_original.php';
    exit;
}

$currentUser = [
    'u_ID' => (string) ($_SESSION['u_ID'] ?? ''),
    'u_name' => '',
    'role_ID' => isset($_SESSION['role_ID']) ? (int) $_SESSION['role_ID'] : null,
];

if ($currentUser['u_ID'] !== '') {
    try {
        $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ?");
        $stmt->execute([$currentUser['u_ID']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $currentUser['u_name'] = (string) ($row['u_name'] ?? '');
        }
    } catch (Throwable $e) {
    }
}

if ($currentUser['u_name'] === '' && isset($_SESSION['u_name'])) {
    $currentUser['u_name'] = (string) $_SESSION['u_name'];
}

?>
<link rel="stylesheet" href="css/apply_test.css">
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script>
    window.CURRENT_USER = <?= json_encode($currentUser, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.APPLY_TEST_CACHE_VERSION = <?= json_encode((string) time(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<header>
    <h2 class="mb-4">申請文件上傳 </h2>
    <p class="text-muted small mb-0">表單由科辦在「表單管理」開放，依目標設定（學級、類組等）顯示您可填寫的文件。</p>
</header>

<div id="app" class="main" v-cloak>
    <div class="form-fill-container">
        <div class="form-card">
            <!-- 送出後明顯提示：該表單該組已完成繳交，一份表單一組僅能填寫一次 -->
            <div v-if="selectedFileID && isSubmitted" class="alert alert-success submitted-banner mb-4 py-3 px-4"
                role="alert" style="border-left: 5px solid #198754; font-size: 1.05rem;">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-circle-check me-3" style="font-size: 1.5rem;"></i>
                    <div>
                        <strong class="d-block mb-1">本表單該組已完成繳交</strong>
                        <span class="text-dark">一份表單一組僅能填寫一次，無法再次修改或重送。</span>
                        <div v-if="submittedAt" class="mt-2 small text-muted">提交時間：{{ submittedAt }}</div>
                    </div>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="file_ID">選擇表單類型：</label>
                <select v-model="selectedFileID" id="file_ID" class="form-control" required>
                    <option disabled value="">請選擇表單</option>
                    <option v-for="file in files" :key="file.doc_ID" :value="file.doc_ID">
                        {{ getFileOptionText(file) }}
                    </option>
                </select>
                <div v-if="!loading && files.length === 0" class="alert alert-info mt-3" role="alert">
                    <i class="fa-solid fa-info-circle me-2"></i>
                    <strong>目前沒有可用的表單</strong>
                    <p class="mb-0 mt-2" style="font-size: 14px;">
                        可能的原因：<br>
                        • 表單尚未開放或已過期<br>
                        • 表單的目標設定對象與您不符（例如：學級、類組不匹配）<br>
                        • 您尚未加入專題團隊（某些表單需要）<br>
                        • 目前沒有啟用的表單
                    </p>
                </div>
                <div v-if="selectedFile && (selectedFile.doc_start_d || selectedFile.doc_end_d)"
                    class="mt-3 p-3 border rounded" style="background-color: #f8f9fa;">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fa-solid fa-calendar-clock me-2 text-primary"></i>
                        <strong class="text-dark">時間資訊</strong>
                    </div>
                    <div class="row g-2">
                        <div v-if="selectedFile.doc_start_d" class="col-12">
                            <small class="text-muted d-block mb-1"><i
                                    class="fa-solid fa-play-circle me-1 text-success"></i>開放時間：</small>
                            <div class="fw-bold text-success">{{ formatDateTime(selectedFile.doc_start_d) }}</div>
                        </div>
                        <div v-if="selectedFile.doc_end_d" class="col-12">
                            <small class="text-muted d-block mb-1"><i class="fa-solid fa-stop-circle me-1"
                                    :class="{'text-danger': isExpired(selectedFile.doc_end_d), 'text-warning': !isExpired(selectedFile.doc_end_d)}"></i>截止時間：</small>
                            <div class="fw-bold"
                                :class="{'text-danger': isExpired(selectedFile.doc_end_d), 'text-warning': !isExpired(selectedFile.doc_end_d)}">
                                {{ formatDateTime(selectedFile.doc_end_d) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">申請人姓名：</label>
                    <input type="text" class="form-control" id="apply_user" v-model="applyUser" readonly>
                </div>

                <!-- 專題基本資料：固定顯示專題題目與組員（由後端自動帶入，學生唯讀） -->
                <div v-if="projectData && projectData.has_team" class="mt-3 p-3 border rounded"
                    style="background-color: #f0f4ff;">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fa-solid fa-users-line me-2 text-primary"></i>
                        <strong class="text-dark">專題資訊</strong>
                    </div>
                    <div class="small">
                        <div class="mb-1">
                            <strong>專題題目：</strong>
                            <span>{{ projectData.project_title || '尚未設定' }}</span>
                        </div>
                        <div class="mb-1">
                            <strong>專題組員：</strong>
                            <template v-if="projectData.students && projectData.students.length">
                                <span v-for="(s, idx) in projectData.students" :key="s.u_ID || idx">
                                    {{ (s.student_id || s.u_ID) + ' ' + (s.u_name || '') }}<span
                                        v-if="idx < projectData.students.length - 1">、</span>
                                </span>
                            </template>
                            <span v-else>尚未設定</span>
                        </div>
                        <div>
                            <strong>指導老師：</strong>
                            <span>{{ projectData.advisor || '尚未設定' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 題型與 form_manage 一致：文字(單/多行)、日期、單選/複選、表格、子題、數字、圖檔/附件上傳 -->
            <div v-if="selectedForm" class="form-schema-content">
                <div v-for="(q, qIndex) in fillableQuestions" :key="'q'+q.order" class="question-group mt-3"
                    :class="{ 'readonly': isSubmitted, 'required': q.required }">
                    <label class="question-label">{{ (qIndex+1) + '. ' + (q.title || '') }}</label>
                    <!-- 文字（題目標題為評分/簽名/概述/分工等 → 多行可預覽；審查/簽名欄位較小、反黑不可輸入） -->
                    <div v-if="q.type === 'text' && useTextareaForQuestion(q)" class="mt-1">
                        <textarea class="form-control"
                            :class="{ 'textarea-large': q.textarea_display === 'large', 'review-field-locked': isReviewField(q) && !canEditReviewFields }"
                            :rows="getTextareaRows(q)" v-model="formAnswers['q_'+q.order]"
                            :placeholder="isReviewField(q) ? getReviewFieldHint(q) : (q.placeholder || '')"
                            :readonly="isSubmitted || (isReviewField(q) && !canEditReviewFields)"></textarea>
                    </div>
                    <!-- 文字（單行） -->
                    <div v-else-if="q.type === 'text'" class="mt-1">
                        <input type="text" class="form-control" v-model="formAnswers['q_'+q.order]"
                            :placeholder="q.placeholder || ''" :readonly="isSubmitted">
                    </div>
                    <!-- 文字（多行題型） -->
                    <div v-else-if="q.type === 'textarea' || q.type === 'numbered_textarea'" class="mt-1">
                        <textarea class="form-control"
                            :class="{ 'textarea-large': q.textarea_display === 'large', 'review-field-locked': isReviewField(q) && !canEditReviewFields }"
                            :rows="getTextareaRows(q)" v-model="formAnswers['q_'+q.order]"
                            :placeholder="isReviewField(q) ? getReviewFieldHint(q) : (q.placeholder || '')"
                            :readonly="isSubmitted || (isReviewField(q) && !canEditReviewFields)"></textarea>
                    </div>
                    <!-- 日期 -->
                    <div v-else-if="q.type === 'date'" class="mt-1">
                        <input type="text" class="form-control date-picker-input" v-model="formAnswers['q_'+q.order]"
                            placeholder="請選擇日期" readonly :required="q.required">
                    </div>
                    <!-- 數字/評分 -->
                    <div v-else-if="q.type === 'number'" class="mt-1">
                        <input type="number" class="form-control" v-model.number="formAnswers['q_'+q.order]"
                            :min="q.min_val != null ? q.min_val : 0" :max="q.max_val != null ? q.max_val : 100"
                            :placeholder="q.placeholder || ''" :readonly="isSubmitted">
                    </div>
                    <!-- 單選 -->
                    <div v-else-if="q.type === 'radio'" class="mt-1 options-container">
                        <div v-for="(opt, optIdx) in (q.options || [])" :key="optIdx" class="option-item">
                            <label>
                                <input type="radio" v-model="formAnswers['q_'+q.order]" :value="opt"
                                    :required="q.required" :disabled="isSubmitted">
                                {{ opt }}
                            </label>
                        </div>
                    </div>
                    <!-- 複選 -->
                    <div v-else-if="q.type === 'checkbox'" class="mt-1 options-container">
                        <div v-for="(opt, optIdx) in (q.options || [])" :key="optIdx" class="option-item">
                            <label>
                                <input type="checkbox" :value="opt" @change="updateCheckbox('q_'+q.order, opt, $event)"
                                    :checked="(formAnswers['q_'+q.order] || []).includes(opt)" :disabled="isSubmitted">
                                {{ opt }}
                            </label>
                        </div>
                    </div>
                    <!-- 表格 -->
                    <div v-else-if="q.type === 'table'" class="mt-1 table-editor">
                        <p class="text-muted small mb-2">列數、欄數可調整；可直接在格子內輸入。</p>
                        <div class="row mb-2 g-2 align-items-center">
                            <div class="col-auto"><label class="col-form-label small">列</label><input type="number"
                                    class="form-control form-control-sm" style="width:4rem;"
                                    :value="getTableState('q_'+q.order).rows"
                                    @input="setTableRows(q, $event.target.value)" min="1" max="20"></div>
                            <div class="col-auto"><label class="col-form-label small">欄</label><input type="number"
                                    class="form-control form-control-sm" style="width:4rem;"
                                    :value="getTableState('q_'+q.order).cols"
                                    @input="setTableCols(q, $event.target.value)" min="1" max="10"></div>
                        </div>
                        <table class="table table-bordered student-answer-table">
                            <tbody>
                                <tr v-for="(row, ri) in getTableState('q_'+q.order).cells" :key="ri">
                                    <td v-for="(cell, ci) in row" :key="ci" class="align-top">
                                        <textarea class="form-control form-control-sm" rows="2"
                                            :value="getTableCell('q_'+q.order, ri, ci)"
                                            @input="onTableCellInput('q_'+q.order, ri, ci, $event.target.value)"
                                            :readonly="isSubmitted" placeholder=""></textarea>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 子題多小題 -->
                    <div v-else-if="q.type === 'sub_questions' && (q.subs || []).length"
                        class="mt-1 sub-questions-block">
                        <div v-for="(sub, si) in (q.subs || [])" :key="si" class="mb-3">
                            <label class="form-label small">{{ sub.label || '小題 '+(si+1) }}</label>
                            <input v-if="!sub.type || sub.type === 'text'" type="text" class="form-control"
                                v-model="formAnswers['q_'+q.order+'_sub_'+si]" :readonly="isSubmitted">
                            <textarea v-else-if="sub.type === 'textarea'" class="form-control" :rows="sub.rows || 2"
                                v-model="formAnswers['q_'+q.order+'_sub_'+si]" :readonly="isSubmitted"></textarea>
                            <div v-else-if="sub.type === 'radio' && (sub.options || []).length"
                                class="options-container">
                                <label v-for="(opt, oi) in (sub.options || [])" :key="oi" class="option-item me-3">
                                    <input type="radio" v-model="formAnswers['q_'+q.order+'_sub_'+si]" :value="opt"
                                        :disabled="isSubmitted"> {{ opt }}
                                </label>
                            </div>
                            <div v-else-if="sub.type === 'checkbox' && (sub.options || []).length"
                                class="options-container">
                                <label v-for="(opt, oi) in (sub.options || [])" :key="oi" class="option-item me-3">
                                    <input type="checkbox" :value="opt"
                                        @change="updateSubCheckbox(q.order, si, opt, $event)"
                                        :checked="getSubCheckboxChecked(q.order, si, opt)" :disabled="isSubmitted"> {{
                                    opt }}
                                </label>
                            </div>
                        </div>
                    </div>
                    <!-- 圖檔上傳（學生上傳） -->
                    <div v-else-if="q.type === 'image_upload'" class="mt-1">
                        <input type="file" class="form-control" accept="image/jpeg,image/jpg,image/png,image/webp"
                            @change="onQuestionImageChange(q, $event)" :disabled="isSubmitted"
                            :ref="el => setQuestionImageInputRef(q.order, el)">
                        <p v-if="questionImagePreview['q_'+q.order]" class="mt-2 small"><img
                                :src="questionImagePreview['q_'+q.order]" alt="預覽"
                                style="max-height:120px; border:1px solid #ddd;"></p>
                    </div>
                    <!-- 附件上傳（PDF/Word） -->
                    <div v-else-if="q.type === 'file_upload'" class="mt-1">
                        <input type="file" class="form-control"
                            accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                            @change="onQuestionFileChange(q, $event)" :disabled="isSubmitted">
                        <span v-if="formAnswers['q_'+q.order]" class="small text-muted">{{ formAnswers['q_'+q.order]
                            }}</span>
                    </div>
                    <!-- 專題生+指導老師（手動輸入） -->
                    <div v-else-if="q.type === 'students_advisor'" class="mt-1 students-advisor-block">
                        <div class="mb-3">
                            <label class="form-label small">專題生</label>
                            <div v-for="(s, si) in (getProjectStudents(q.order).length ? getProjectStudents(q.order) : [{ id: '', name: '' }])"
                                :key="si" class="student-row mb-2">
                                <input type="text" class="form-control d-inline-block me-2" style="width:48%;"
                                    v-model="formAnswers['q_'+q.order+'_student_'+si+'_id']" placeholder="學號"
                                    :readonly="isSubmitted">
                                <input type="text" class="form-control d-inline-block" style="width:48%;"
                                    v-model="formAnswers['q_'+q.order+'_student_'+si+'_name']" placeholder="姓名"
                                    :readonly="isSubmitted">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">指導老師</label>
                            <input type="text" class="form-control" v-model="formAnswers['q_'+q.order+'_advisor']"
                                placeholder="請輸入指導老師姓名" :readonly="isSubmitted">
                        </div>
                    </div>
                    <!-- 預設：單行文字 -->
                    <div v-else class="mt-1">
                        <input type="text" class="form-control" v-model="formAnswers['q_'+q.order]"
                            :placeholder="q.placeholder || ''" :readonly="isSubmitted">
                    </div>
                </div>
            </div>

            <template v-if="files.length > 0 && supplementEnabled">
                <div class="question-group mt-4">
                    <label class="question-label">補充附件（PDF）</label>
                    <p class="small text-muted mb-2">{{ supplementNote }}</p>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="triggerAttachmentInput"
                            :disabled="isSubmitted">選擇 PDF 檔案</button>
                        <span class="supplement-pdf-filename" :class="{ 'text-muted': !displayAttachName }">{{
                            displayAttachName || '未選擇檔案' }}</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-2" @click="resetAttachment()"
                            :disabled="!displayAttachName">重置</button>
                    </div>
                    <input type="file" accept=".pdf,application/pdf" ref="supplementPdfInput"
                        @change="onSupplementPdfChange" class="d-none" tabindex="-1">
                </div>
            </template>

            <div v-if="showSystemInfo" class="question-group mt-4"
                style="background: #f8f9fa; border-left: 4px solid #6c757d;">
                <label class="question-label text-muted">系統資訊</label>
                <div class="small text-muted">
                    <div>最後修改時間：{{ displayLastUpdated ? formatDateTime(displayLastUpdated) : '尚未暫存' }}</div>
                    <div v-if="submittedAt" class="mt-1"><strong>提交時間與狀態：</strong>{{ submittedAt }}</div>
                </div>
            </div>
            <a v-if="showExresultdataNotice"
                @click.prevent="goExpectedOutcome"
                class="question-group mt-4 d-block text-decoration-none"
                style="background: #fff5f5; border-left: 4px solid #dc3545; border: 1px solid #f5c2c7; border-left-width: 4px; border-radius: 8px; padding: 16px; cursor: pointer;">
                <div class="question-label mb-2"
                    style="color: #dc3545; font-weight: 700;">注意</div>
                <div class="small" style="color: #842029;">
                    此申請表須連同預期成果一併繳交，送出前請先確認預期成果已完成。
                    <span style="font-weight: 700; text-decoration: underline;">（點擊前往填寫）</span>
                </div>
            </a>

            <template v-if="files.length > 0">
                <div class="form-action-buttons text-center mt-4">
                    <button type="button" class="btn-draft-apply" @click="saveDraft()"
                        :disabled="!selectedFileID || isSubmitted">
                        <i class="fa-solid fa-save me-2"></i>
                        <span v-text="draftSaved ? '已暫存' : '暫存'"></span>
                    </button>
                    <button type="button" class="btn-export" @click="previewPdf()" :disabled="isSubmitted">
                        <i class="fa-solid fa-file-pdf me-2"></i>預覽 PDF 檔
                    </button>
                    <button type="button" class="btn-submit" @click="submitForm()" :disabled="!canSubmit || isSubmitted"
                        :title="(!canSubmit && !isSubmitted) ? '請先：選擇表單並填寫所有必填欄位' : ''">
                        <i class="fa-solid fa-paper-plane me-2"></i>送出申請
                    </button>
                </div>
                <p class="small text-muted text-center mt-2 mb-0" v-if="!isSubmitted">
                    暫存僅保存草稿；若要傳送至科辦，請按「送出申請」並在彈窗中按「確認提交」。</p>
            </template>
        </div>

        <div v-if="loading" class="loading">
            <i class="fa-solid fa-spinner fa-spin fa-2x"></i>
            <p>載入中...</p>
        </div>

        <div v-if="!loading && files.length === 0 && !selectedFileID" class="form-card">
            <div class="text-center" style="padding: 40px 20px;">
                <i class="fa-solid fa-inbox" style="font-size: 48px; color: #667eea; margin-bottom: 20px;"></i>
                <h3 style="color: #495057; margin-bottom: 15px;">目前沒有可用的表單</h3>
                <p style="color: #6c757d; line-height: 1.8;">系統會根據您的學級、類組等資訊自動過濾表單。<br>如果您認為應該看到某些表單，請聯繫科辦確認您的資料是否正確。
                </p>
            </div>
        </div>

        <!-- 原核實對比彈窗已移除：學生填寫完可直接送出 -->
    </div>
</div>

<script src="js/apply_test.js"></script>