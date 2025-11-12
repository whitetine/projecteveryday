<!-- modules/notify.php -->
<div class="modal fade" id="notifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header align-items-center">

                <h5 class="ms-3 mb-0">新增資訊</h5>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body：把整份表單放這裡 -->
            <form id="notifyForm" class="modal-body" enctype="multipart/form-data">
                <div class="row gy-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header"><strong>基本資料</strong></div>
                            <div class="card-body">
                                <!-- 資訊名稱 / 連結網址 -->
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">＊資訊名稱</label>
                                        <input type="text" class="form-control" name="title" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">連結網址</label>
                                        <div class="input-group">
                                            <span class="input-group-text">http:// 或 https://</span>
                                            <input type="url" class="form-control" name="link" placeholder="可留空">
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <!-- 詳細說明 + 右側欄 -->
                                <div class="row">
                                    <div class="col-lg-8">
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <span class="fw-bold">詳細說明</span>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="content_mode" id="modeText" value="text" checked>
                                                <label class="form-check-label" for="modeText">純文字</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="content_mode" id="modeHtml" value="html">
                                                <label class="form-check-label" for="modeHtml">HTML</label>
                                            </div>
                                        </div>

                                        <textarea id="plainTextarea" class="form-control" name="content_text" rows="10" placeholder="輸入純文字內容..."></textarea>
                                        <textarea id="htmlEditor" class="form-control d-none" name="content_html" rows="10"></textarea>
                                        <div class="form-text">可二擇一：送出時依勾選模式提交內容。</div>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="card border-0 bg-light">
                                            <div class="card-body">
                                                <!-- <div class="mb-3">
                                                    <label class="form-label">分類</label>
                                                    <select class="form-select" name="categories[]" multiple size="6">
                                                        <option value="news">最新消息</option>
                                                        <option value="announce">公告</option>
                                                        <option value="activity">活動</option>
                                                    </select>
                                                    <div class="form-text">可按住 Ctrl 多選（Mac ⌘）。</div>
                                                </div> -->

                                                <div class="mb-3">
                                                    <label class="form-label">資訊編號</label>
                                                    <input type="text" class="form-control" name="code" placeholder="可留空自動編號">
                                                </div>

                                                <div class="row g-2 mb-3">
                                                    <div class="col-12">
                                                        <label class="form-label">發佈日期</label>
                                                        <input type="datetime-local" class="form-control" name="start_dt">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">到期日期</label>
                                                        <input type="datetime-local" class="form-control" name="end_dt">
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label d-block">資訊狀態</label>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="status" id="stOn" value="1" checked>
                                                        <label class="form-check-label" for="stOn">啟用</label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="status" id="stOff" value="0">
                                                        <label class="form-check-label" for="stOff">停用</label>
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label d-block">公告顯示</label>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="show_home" id="homeYes" value="1" checked>
                                                        <label class="form-check-label" for="homeYes">顯示</label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="radio" name="show_home" id="homeNo" value="0">
                                                        <label class="form-check-label" for="homeNo">不顯示</label>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <!-- 上傳 -->
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">上傳圖片（可多選）</label>
                                        <input class="form-control" type="file" name="images[]" accept="image/*" multiple>
                                        <div class="form-text">建議 PNG/JPG；大小由後端限制。</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">上傳附件（可多選）</label>
                                        <input class="form-control" type="file" name="files[]" multiple>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-success" id="btnSaveAndBack">新增並返回</button>
                <button type="button" class="btn btn-primary" id="btnSave">新增</button>
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">取消</button>
                <small class="text-muted ms-auto">狀態：正常</small>

            </div>
            <!-- <div class="d-flex gap-2"> -->

            <!-- </div> -->
        </div>
    </div>
</div>