<?php
session_start();
require '../includes/pdo.php'; // 取得 $conn (PDO)
?>
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
                    <!-- 選擇表單類型與申請人姓名：一行布局 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="file_ID">選擇表單類型：</label>
                            <select v-model="selectedFileID"
                                name="file_ID" id="file_ID"
                                class="form-select" required>
                                <option disabled value="">請選擇表單</option>
                                <option v-for="file in files" :key="file.file_ID" :value="file.file_ID">
                                    {{ file.file_name }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="apply_user">申請人姓名：</label>
                            <!-- 1015update -->
                            <input type="text"
                                class="form-control"
                                id="apply_user"
                                :value="applyUser"
                                readonly>
                            <!-- ----------- -->

                            <input type="hidden" v-model="applyUser" name="apply_user">

                        </div>

                        <!-- 檔案名稱/其他備註 -->
                        <div class="mb-4">
                            <label for="apply_other" class="form-label">檔案名稱/其他備註：</label>
                            <textarea v-model="applyOther"
                                class="form-control"
                                id="apply_other"
                                name="apply_other"
                                rows="3"
                                placeholder="請輸入檔案名稱或附加說明..."></textarea>
                        </div>

                        <!-- 上傳圖片 -->
                        <div class="mb-4">
                            <label for="apply_image" class="form-label">上傳圖片（PNG/JPG）：</label>
                            <input type="file"
                                ref="applyImage"
                                class="form-control"
                                name="apply_image"
                                id="apply_image"
                                accept="image/png, image/jpeg"
                                @change="previewImage" />
                        </div>

                        <!-- 圖片預覽區塊 -->
                        <div v-if="imagePreview" class="card mb-4 shadow-sm">
                            <div class="card-header bg-light">
                                <strong>圖片預覽</strong>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <label class="form-label mb-0">預覽大小：</label>
                                    <span class="text-muted"><strong>{{ previewPercent }}%</strong></span>
                                    <input type="range"
                                        class="form-range flex-grow-1"
                                        min="10" max="100" step="5"
                                        v-model.number="previewPercent"
                                        aria-label="調整預覽大小">
                                </div>
                                <div class="preview-box text-center"
                                    :style="{ width: previewPercent + '%', maxWidth: '100%', margin: '0 auto' }">
                                    <img :src="imagePreview"
                                        class="preview-img img-fluid rounded shadow"
                                        alt="圖片預覽"
                                        style="max-height: 400px; object-fit: contain;">
                                </div>
                            </div>
                        </div>

                        <!-- 提交按鈕 -->
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-lg px-4">送出申請</button>
                        </div>
                </form>
            </div>
        </div>

        <!-- 範例檔案預覽區塊 -->
        <div class="card shadow-sm" v-if="selectedFileUrl">
            <div class="card-header bg-secondary text-white">
                <strong>範例檔案預覽</strong>
            </div>
            <div class="card-body p-0">
                <iframe :src="selectedFileUrl"
                    class="w-100"
                    style="height: 400px; border: none; border-radius: 0 0 0.375rem 0.375rem;"
                    title="範例檔案"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
    window.CURRENT_USER = <?= json_encode(['u_ID' => (string)($_SESSION['u_ID'] ?? '')], JSON_UNESCAPED_UNICODE) ?>;
</script>