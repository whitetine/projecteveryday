<?php
session_start();
require '../includes/pdo.php'; // 取得 $conn (PDO)

// 🔹 查詢申請人姓名（從資料庫 userdata 表）
$currentUser = [
    'u_ID' => (string)($_SESSION['u_ID'] ?? ''),
    'u_name' => '',
];

if ($currentUser['u_ID'] !== '') {
    try {
        $stmt = $conn->prepare("SELECT u_ID, u_name FROM userdata WHERE u_ID = ?");
        $stmt->execute([$currentUser['u_ID']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $currentUser['u_name'] = (string)($row['u_name'] ?? '');
        }
    } catch (Throwable $e) {
        // 若查詢失敗則退回 session 內的名稱
    }
}

// 如果資料庫查不到，嘗試從 session 取得
if ($currentUser['u_name'] === '' && isset($_SESSION['u_name'])) {
    $currentUser['u_name'] = (string)$_SESSION['u_name'];
}
?>
<!-- 樣式必須在 #app 外面 -->
<style>
    /* 自定義滑桿樣式 - 綠色滑桿按鈕 */
    .custom-range::-webkit-slider-thumb {
        background-color: #28a745 !important;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        width: 18px;
        height: 18px;
    }

    .custom-range::-moz-range-thumb {
        background-color: #28a745 !important;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        width: 18px;
        height: 18px;
    }

    .custom-range::-ms-thumb {
        background-color: #28a745 !important;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        width: 18px;
        height: 18px;
    }

    .custom-range:focus::-webkit-slider-thumb {
        box-shadow: 0 0 0 0.25rem rgba(40, 167, 69, 0.25);
    }

    .custom-range:focus::-moz-range-thumb {
        box-shadow: 0 0 0 0.25rem rgba(40, 167, 69, 0.25);
    }

    /* 滑桿軌道樣式 */
    .custom-range::-webkit-slider-runnable-track {
        background-color: #dee2e6;
        height: 6px;
        border-radius: 3px;
    }

    .custom-range::-moz-range-track {
        background-color: #dee2e6;
        height: 6px;
        border-radius: 3px;
    }

    .custom-range::-ms-track {
        background-color: #dee2e6;
        height: 6px;
        border-radius: 3px;
    }
</style>

<script>
    // 設置全局變數，必須在 Vue 掛載前執行
    window.CURRENT_USER = <?= json_encode($currentUser, JSON_UNESCAPED_UNICODE) ?>;
</script>

<header>
    <h2 class="mb-4">申請文件上傳</h2>
</header>

<div id="app" class="main container">
    <div id="apply-uploader">

        <!-- 上傳區卡片 -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                <strong>上傳區</strong>
            </div>
            <div class="card-body">
                <form @submit.prevent="submitForm" enctype="multipart/form-data" id="applyForm">

                    <!-- 選擇表單類型與申請人姓名 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="file_ID">選擇表單類型：</label>
                            <select v-model="selectedFileID" name="file_ID" id="file_ID" class="form-select" required>
                                <option disabled value="">請選擇表單</option>
                                <option v-for="file in files" :key="file.doc_ID" :value="file.doc_ID">
                                    {{ getFileOptionText(file) }}
                                </option>
                            </select>

                            <!-- 顯示開放和截止時間（更明顯的顯示） -->
                            <div v-if="selectedFile && (selectedFile.doc_start_d || selectedFile.doc_end_d)" class="mt-3 p-3 border rounded" style="background-color: #f8f9fa;">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="fa-solid fa-calendar-clock me-2 text-primary"></i>
                                    <strong class="text-dark">時間資訊</strong>
                                </div>
                                <div class="row g-2">
                                    <div v-if="selectedFile.doc_start_d" class="col-12">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fa-solid fa-play-circle me-1 text-success"></i>開放時間：
                                        </small>
                                        <div class="fw-bold text-success">{{ formatDateTime(selectedFile.doc_start_d) }}</div>
                                    </div>
                                    <div v-if="selectedFile.doc_end_d" class="col-12">
                                        <small class="text-muted d-block mb-1">
                                            <i class="fa-solid fa-stop-circle me-1" :class="{'text-danger': isExpired(selectedFile.doc_end_d), 'text-warning': !isExpired(selectedFile.doc_end_d)}"></i>截止時間：
                                        </small>
                                        <div class="fw-bold" :class="{'text-danger': isExpired(selectedFile.doc_end_d), 'text-warning': !isExpired(selectedFile.doc_end_d)}">
                                            {{ formatDateTime(selectedFile.doc_end_d) }}
                                        </div>
                                    </div>
                                </div>
                                <div v-if="selectedFile.doc_end_d && isExpired(selectedFile.doc_end_d)" class="alert alert-danger mt-2 py-2 mb-0" role="alert">
                                    <i class="fa-solid fa-exclamation-triangle me-2"></i>
                                    <strong>此文件已過期，無法提交</strong>
                                </div>
                                <div v-else-if="selectedFile.doc_start_d && !isStarted(selectedFile.doc_start_d)" class="alert alert-info mt-2 py-2 mb-0" role="alert">
                                    <i class="fa-solid fa-info-circle me-2"></i>
                                    <strong>此文件尚未開放</strong>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="apply_user">申請人姓名：</label>
                            <input type="text" class="form-control" id="apply_user" v-model="applyUser" :value="applyUser || '<?= htmlspecialchars($currentUser['u_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>'" value="<?= htmlspecialchars($currentUser['u_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>

                            <!-- 🔹隱藏欄位：確保表單送出時有帶值 -->
                            <input type="hidden" name="apply_user" :value="applyUser || '<?= htmlspecialchars($currentUser['u_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>'">
                        </div>

                        <!-- 檔案名稱/其他備註 -->
                        <div class="mb-4">
                            <label for="apply_other" class="form-label">檔案名稱/其他備註：</label>
                            <textarea v-model="applyOther" class="form-control" id="apply_other" name="apply_other"
                                rows="3" placeholder="請輸入檔案名稱或附加說明..."></textarea>
                        </div>

                        <!-- 防呆提示文字 -->
                        <div v-if="hasSubmitted" class="alert alert-warning mb-4" role="alert">
                            <i class="fa-solid fa-exclamation-triangle me-2"></i>
                            <strong>本組已有成員送出過此文件</strong>
                        </div>

                        <!-- 上傳圖片 -->
                        <div class="mb-4">
                            <label for="apply_image" class="form-label">上傳圖片（PNG/JPG）：</label>
                            <input type="file" ref="applyImage" class="form-control" name="apply_image" id="apply_image"
                                accept="image/png, image/jpeg" @change="previewImage" />
                        </div>
                        <div v-if="needExresultdataWarning" class="mb-4">
                            <div class="alert alert-warning d-flex align-items-center mb-0" role="alert">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                                <span>
                                    <strong>注意：</strong>此申請表須連同預期成果一併繳交，送出前請先確認預期成果已完成。(點擊前往填寫)
                                </span>
                            </div>
                        </div>
                        <!-- 提交按鈕 -->
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-lg px-4" :disabled="!canSubmit">
                                <span v-if="selectedFile && selectedFile.doc_end_d && isExpired(selectedFile.doc_end_d)">已過期</span>
                                <span v-else-if="selectedFile && selectedFile.doc_start_d && !isStarted(selectedFile.doc_start_d)">尚未開放</span>
                                <span v-else>送出申請</span>
                            </button>
                        </div>
                </form>
            </div>
        </div>

        <!-- 圖片預覽區塊 -->
        <div v-if="imagePreview" class="card mb-4 shadow-sm mt-4">
            <div class="card-header bg-light">
                <strong>圖片預覽</strong>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label mb-2">預覽縮放：</label>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="zoomOut" title="縮小">
                            <i class="fa-solid fa-magnifying-glass-minus"></i>
                        </button>
                        <span class="text-dark fw-bold" style="min-width: 50px; text-align: center;">{{ previewPercent }}%</span>
                        <input type="range" class="form-range flex-grow-1 custom-range" min="10" max="200" step="1"
                            v-model.number="previewPercent" aria-label="調整預覽縮放"
                            style="--bs-range-thumb-bg: #28a745;">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="zoomIn" title="放大">
                            <i class="fa-solid fa-magnifying-glass-plus"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="resetZoom" title="重置為100%">
                            <i class="fa-solid fa-rotate-left"></i> 重置
                        </button>
                    </div>
                </div>
                <div class="preview-box text-center" style="margin: 0 auto; width: 100%; overflow: auto; max-height: 600px; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 15px; background-color: #f8f9fa;">
                    <img :src="imagePreview"
                        class="preview-img rounded shadow"
                        alt="圖片預覽"
                        :style="{
                             width: imageWidth ? (imageWidth * previewPercent / 100) + 'px' : 'auto',
                             height: 'auto',
                             maxWidth: previewPercent <= 100 ? '100%' : 'none',
                             objectFit: 'contain',
                             display: 'block',
                             margin: '0 auto'
                         }"
                        @load="onImageLoad">
                </div>
            </div>
        </div>

        <!-- 範例檔案預覽區塊 -->
        <div id="example-preview-card" class="card shadow-sm mt-4" v-show="selectedFileID && selectedFileUrl">
            <div class="card-header bg-secondary text-white">
                <strong><i class="fa-solid fa-file-pdf me-2"></i>範例檔案預覽</strong>
            </div>
            <div class="card-body p-0" style="background-color: #525252; height: calc(100vh - 300px); min-height: 800px;">
                <div v-if="selectedFileUrl" style="width: 100%; height: 100%; position: relative; overflow: hidden;">
                    <iframe :src="selectedFileUrl"
                        class="w-100 h-100"
                        style="border: none; display: block; width: 100%; height: 100%; position: absolute; top: 0; left: 0;"
                        title="範例檔案"
                        frameborder="0"
                        scrolling="no"
                        @error="handleIframeError"
                        @load="handleIframeLoad"></iframe>
                </div>
                <div v-else class="p-4 text-center text-muted bg-light">
                    <i class="fa-solid fa-exclamation-triangle me-2"></i>無法載入預覽
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 腳本必須在 #app 外面，避免被 Vue 編譯 -->
<script>
    // 確保申請人姓名在 DOM 載入後立即設置（在 Vue 掛載前）
    (function() {
        function setUserName() {
            if (window.CURRENT_USER && window.CURRENT_USER.u_name) {
                const inputEl = document.getElementById('apply_user');
                if (inputEl) {
                    inputEl.value = window.CURRENT_USER.u_name;
                }
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setUserName);
        } else {
            setTimeout(setUserName, 0);
        }
    })();

    // 阻止 app.js 中的 renderApplyPage 被調用，改用我們的 mountApplyUploader
    (function() {
        // 在 DOM 載入後執行，確保在 renderApplyPage 被調用之前覆蓋
        function overrideRenderApplyPage() {
            // 覆蓋 renderApplyPage，使用我們的實現
            window.renderApplyPage = function(mountSel) {
                // 先載入 apply-uploader.js
                if (typeof window.mountApplyUploader !== 'function') {
                    const script = document.createElement('script');
                    script.src = 'js/apply-uploader.js?v=<?= time() ?>';
                    script.onload = function() {
                        if (typeof window.mountApplyUploader === 'function') {
                            const mountEl = document.querySelector(mountSel || '#app');
                            if (mountEl && !mountEl.__vue_app__) {
                                window.mountApplyUploader(mountSel || '#app');
                            }
                        }
                    };
                    document.head.appendChild(script);
                } else {
                    const mountEl = document.querySelector(mountSel || '#app');
                    if (mountEl && !mountEl.__vue_app__) {
                        window.mountApplyUploader(mountSel || '#app');
                    }
                }
            };
        }

        // 立即執行，確保在 app.js 的 renderApplyPage 被調用之前覆蓋
        overrideRenderApplyPage();

        // 也監聽 DOMContentLoaded，以防萬一
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', overrideRenderApplyPage);
        }
    })();
</script>