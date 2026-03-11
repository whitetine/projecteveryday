    <?php
    session_start();
    if (!isset($_SESSION['u_ID'])) {
        echo "<script>alert('請先登入');location.href='../index.php';</script>";
        exit;
    }

    $role_ID = $_SESSION['role_ID'] ?? 0;
    if ($role_ID != 6) {
        echo "<script>alert('此頁面僅限學生使用');location.href='../main.php';</script>";
        exit;
    }

    require_once __DIR__ . '/../includes/pdo.php';

    $u_ID = $_SESSION['u_ID'];

    // 檢查學生是否已有團隊
    $hasTeam = false;
    $currentTeam = null;

    try {
        // 檢查 teammember 表結構（兼容兩種版本）
        $teamUserField = 'team_u_ID';
        $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $teamUserField = 'u_ID';
        }

        $sql = "SELECT t.team_ID, t.team_project_name, t.team_status, t.cohort_ID
                FROM teamdata t
                INNER JOIN teammember tm ON t.team_ID = tm.team_ID
                WHERE tm.{$teamUserField} = ? AND t.team_status = 1
                ORDER BY t.team_update_d DESC
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$u_ID]);
        $currentTeam = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($currentTeam) {
            $hasTeam = true;
        }
    } catch (Exception $e) {
        // 如果查詢失敗，假設沒有團隊
        $hasTeam = false;
    }

    // 獲取當前屆別
    $stmt = $conn->prepare("
        SELECT cohort_ID, cohort_name 
        FROM cohortdata 
        WHERE cohort_status = 1 
        ORDER BY cohort_ID DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $currentCohort = $stmt->fetch(PDO::FETCH_ASSOC);
    $cohort_ID = $currentCohort['cohort_ID'] ?? null;

    // 獲取所有啟用的類組
    $stmt = $conn->prepare("
        SELECT group_ID, group_name 
        FROM groupdata 
        WHERE group_status = 1 
        ORDER BY group_ID
    ");
    $stmt->execute();
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 檢查是否有已提交的申請（待審核或退件狀態）
    // 狀態：1=待審核，2=退件，3=通過
    // 包括：申請者本人 或 被申請的成員
    // === 檢查是否有已提交的申請 ===
    // 狀態：1=待審核，2=退件，3=通過
    // 我們要：pending 只看 1；rejected 另外抓 2
    $pendingApplication  = null;
    $rejectedApplication = null;

    try {
        // 先查「待審核」申請（申請者本人）
        $stmt = $conn->prepare("
            SELECT tap_ID, tap_status, tap_update_d, tap_member
            FROM teamapply
            WHERE tap_u_ID = ? AND tap_status = 1
            ORDER BY tap_update_d DESC
            LIMIT 1
        ");
        $stmt->execute([$u_ID]);
        $pendingApplication = $stmt->fetch(PDO::FETCH_ASSOC);

        // 如果沒有待審核，再看自己是不是「待審核」申請的成員
        if (!$pendingApplication) {
            $stmt = $conn->prepare("
                SELECT tap_ID, tap_status, tap_update_d, tap_member
                FROM teamapply
                WHERE tap_status = 1
                ORDER BY tap_update_d DESC
            ");
            $stmt->execute();
            $allApplications = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allApplications as $app) {
                $member_ids = json_decode($app['tap_member'] ?? '[]', true);
                if (is_array($member_ids) && in_array($u_ID, $member_ids)) {
                    $pendingApplication = $app;
                    break;
                }
            }
        }

        // 再另外抓「退件」的最新一筆（給重填時顯示用）
        $stmt = $conn->prepare("
            SELECT tap_ID, tap_status, tap_update_d, tap_member
            FROM teamapply
            WHERE tap_u_ID = ? AND tap_status = 2
            ORDER BY tap_update_d DESC
            LIMIT 1
        ");
        $stmt->execute([$u_ID]);
        $rejectedApplication = $stmt->fetch(PDO::FETCH_ASSOC);

        // 如果自己不是申請者，就看是否為退件申請裡的成員
        if (!$rejectedApplication) {
            $stmt = $conn->prepare("
                SELECT tap_ID, tap_status, tap_update_d, tap_member
                FROM teamapply
                WHERE tap_status = 2
                ORDER BY tap_update_d DESC
            ");
            $stmt->execute();
            $allRejected = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allRejected as $app) {
                $member_ids = json_decode($app['tap_member'] ?? '[]', true);
                if (is_array($member_ids) && in_array($u_ID, $member_ids)) {
                    $rejectedApplication = $app;
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // 忽略錯誤
    }

    ?>
    <!DOCTYPE html>
    <html lang="zh-Hant">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>專題指導申請單</title>
        <?php include "../head.php"; ?>
        <link rel="stylesheet" href="../css/login.css">
        <link rel="stylesheet" href="../css/team_apply.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>

    <body id="indexbody">
        Wㄋ
        <div class="team-apply-container">
            <?php if ($hasTeam): ?>
                <!-- 已有團隊，顯示提示 -->
                <div class="has-team-section">
                    <div class="has-team-card">
                        <i class="fas fa-check-circle"></i>
                        <h2>您已有專題組別</h2>
                        <p class="team-info">組別名稱：<strong><?= htmlspecialchars($currentTeam['team_project_name']) ?></strong></p>
                        <p class="team-id">組別ID：<?= htmlspecialchars($currentTeam['team_ID']) ?></p>
                        <a href="../main.php#pages/student_milestone.php" class="btn-back">返回任務公佈欄頁面</a>
                    </div>
                </div>
            <?php elseif ($pendingApplication): ?>
                <!-- 已提交的申請（唯讀顯示） -->
                <div class="apply-form-section" id="readonlyFormSection">
                    <div class="form-header">
                        <h1><i class="fas fa-file-alt"></i> 專題指導申請單</h1>
                        <p class="form-subtitle">您已提交的申請資料（無法再做更改）</p>
                        <div class="alert alert-info" style="margin-top: 1rem;">
                            <i class="fas fa-clock"></i> 您的申請正在審核中，請耐心等待。
                        </div>
                    </div>
                    <!-- 審核結果（只在退件時會打開，由 JS 控制） -->
                    <div id="reviewResultCard" class="review-result-card" style="display:none;">
                        <h2 class="review-title">
                            <i class="fas fa-comment-dots"></i>
                            審核結果
                        </h2>
                        <p class="review-meta">
                            審核教師：<span id="reviewerName">—</span>
                            ／ 審核時間：<span id="reviewTime">—</span>
                        </p>

                        <div class="review-block">
                            <label class="review-label">退件原因</label>
                            <div class="review-box">
                                <pre id="reviewRemarkText">審核老師尚未留下備註。</pre>
                            </div>
                        </div>

                        <div class="review-block">
                            <label class="review-label">我當初填寫的備註</label>
                            <div class="review-box secondary">
                                <pre id="userCommentEcho">（無備註內容）</pre>
                            </div>
                        </div>
                    </div>

                    <form id="teamApplyFormReadonly" class="readonly-form">
                        <!-- 唯讀表單內容（由 JavaScript 填充） -->
                        <div id="readonlyFormContent">


                            <!-- 專題名稱 -->
                            <div class="form-group">
                                <label class="required">專題名稱</label>
                                <input type="text" class="form-control" readonly id="readonly_project_name">
                            </div>

                            <!-- 指導老師 / 副指導老師 -->
                            <div class="form-group form-group-inline">
                                <div class="form-group-column">
                                    <label class="required">指導老師</label>
                                    <input type="text" class="form-control" readonly id="readonly_teacher">
                                </div>
                                <div class="form-group-column">
                                    <label>副指導老師</label>
                                    <input type="text" class="form-control" readonly id="readonly_co_teacher" placeholder="未選填">
                                </div>
                            </div>

                            <!-- 類組 -->
                            <div class="form-group">
                                <label class="required">類組</label>
                                <input type="text" class="form-control" readonly id="readonly_group">
                            </div>

                            <!-- 團隊成員 -->
                            <div class="form-group">
                                <label class="required">組別成員</label>
                                <div id="readonly_memberList" class="member-list"></div>
                            </div>


                            <!-- 申請表照片 -->
                            <div class="form-group">
                                <label class="required">專題申請表（紙本照片）</label>
                                <div class="image-upload-container">
                                    <div id="readonly_imagePreview" class="image-preview" style="display: none;">
                                        <img id="readonly_previewImg" src="" alt="申請表照片">
                                    </div>
                                </div>
                            </div>

                            <!-- 說明文字 -->
                            <div class="form-group">
                                <label>說明文字</label>
                                <textarea class="form-control" rows="4" readonly id="readonly_comment"></textarea>
                            </div>

                            <!-- 狀態資訊 -->
                            <div class="form-group">
                                <label>申請狀態</label>
                                <div id="readonly_status" class="status-badge"></div>
                            </div>

                            <!-- 返回按鈕 -->
                            <div class="form-actions">
                                <a href="../index.php" class="btn-back">
                                    <i class="fas fa-sign-out-alt"></i> 登出
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- 申請表單 -->
                <div class="apply-form-section">
                    <?php if ($rejectedApplication): ?>
                        <!-- 有退件紀錄時，顯示審核結果卡片 -->
                        <div class="alert alert-warning" style="margin-top: 1rem;">
                            <i class="fas fa-exclamation-triangle"></i>
                            您上一筆申請在 <?= htmlspecialchars($rejectedApplication['tap_update_d']) ?> 被退件，
                            請參考下方審核備註後重新填寫。
                        </div>

                        <div id="reviewResultCard" class="review-result-card" style="display:none;">
                            <h2 class="review-title">
                                <i class="fas fa-comment-dots"></i>
                                審核結果
                            </h2>
                            <p class="review-meta">
                                審核教師：<span id="reviewerName">—</span>
                                ／ 審核時間：<span id="reviewTime">—</span>
                            </p>

                            <div class="review-block">
                                <label class="review-label">退件原因</label>
                                <div class="review-box">
                                    <pre id="reviewRemarkText">審核老師尚未留下備註。</pre>
                                </div>
                            </div>

                            <div class="review-block">
                                <label class="review-label">我當初填寫的備註</label>
                                <div class="review-box secondary">
                                    <pre id="userCommentEcho">（無備註內容）</pre>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-header">
                        <h1><i class="fas fa-file-alt"></i> 康寧學校財團法人康寧大學 資管科專題指導申請單</h1>
                        <p class="form-subtitle">請在以下填寫正確資訊</p>
                    </div>

                    <form id="teamApplyForm" enctype="multipart/form-data">

                        <!-- 專題名稱 -->
                        <div class="form-group">
                            <label for="project_name" class="required">專題名稱</label>
                            <input type="text"
                                id="project_name"
                                name="project_name"
                                class="form-control"
                                placeholder="請輸入專題名稱"
                                required
                                maxlength="100">
                            <small class="form-text">請輸入您的專題名稱</small>
                        </div>


                        <!-- 指導老師 / 副指導老師 -->
                        <div class="form-group form-group-inline">
                            <div class="form-group-column">
                                <label for="teacher_id" class="required">指導老師</label>
                                <select id="teacher_id" name="teacher_id" class="form-control" required>
                                    <option value="">請選擇指導老師</option>
                                </select>
                                <small class="form-text">請選擇您的專題指導老師</small>
                            </div>
                            <div class="form-group-column">
                                <label for="co_teacher_id">副指導老師</label>
                                <select id="co_teacher_id" name="co_teacher_id" class="form-control">
                                    <option value="">請選擇副指導老師（可留空）</option>
                                </select>
                                <small class="form-text">若有副指導老師可於此選填，未填寫視為無</small>
                            </div>
                        </div>

                        <!-- 類組 -->
                        <div class="form-group">
                            <label for="group_id" class="required">類組</label>
                            <select id="group_id" name="group_id" class="form-control" required>
                                <option value="">請選擇類組</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= htmlspecialchars($group['group_ID']) ?>">
                                        <?= htmlspecialchars($group['group_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text">請選擇您的專題類組</small>
                        </div>

                        <!-- 團隊成員 -->
                        <div class="form-group">
                            <label class="required">組別成員</label>
                            <div class="member-input-container">
                                <div class="member-input-wrapper">
                                    <input type="text"
                                        id="memberInput"
                                        class="form-control member-input"
                                        placeholder="輸入學號（例如：110534201）"
                                        autocomplete="off">
                                    <button type="button" id="addMemberBtn" class="btn-add-member">
                                        <i class="fas fa-plus"></i> 新增
                                    </button>
                                </div>
                                <small class="form-text">請輸入組別成員的學號，系統會自動驗證並顯示姓名</small>
                            </div>
                            <div id="memberList" class="member-list"></div>
                        </div>


                        <!-- 圖片上傳 -->
                        <!-- <div class="form-group">
                            <label for="apply_image" class="required">專題申請表（紙本照片）</label>
                            <div class="image-upload-container" style="width:40%">
                                <input type="file" 
                                    id="apply_image" 
                                    name="apply_image" 
                                    class="form-control file-input" 
                                    accept="image/*"
                                    required>
                                <div id="imagePreview" class="image-preview" style="display: none;">
                                    <img id="previewImg" src="" alt="預覽">
                                    <button type="button" id="removeImageBtn" class="btn-remove-image">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="form-text">請上傳專題申請表的紙本照片（JPG、PNG格式）</small>
                        </div> -->

                        <!-- 說明文字（可選） -->
                        <div class="form-group">
                            <label for="comment">說明文字（選填）</label>
                            <textarea id="comment"
                                name="comment"
                                class="form-control"
                                rows="4"
                                placeholder="如有其他需要說明的事項，請在此填寫"></textarea>
                        </div>
                        <div>註：專題組隊請於 年 月 日前經專題指導老師簽名送至科助處理才算
完成,逾期將不再受理專題組隊。</div>
                        <!-- 提交按鈕 -->
                        <div class="form-actions">
                            <!-- <button type="submit" id="submitBtn" class="btn-submit">
                                <i class="fas fa-paper-plane"></i> 提交申請
                            </button> -->

                            <!-- <button type="button" id="resetBtn" class="btn-reset">
                                <i class="fas fa-redo"></i> 重置表單
                            </button> -->

                            <a href="../index.php" class="btn-back">
                                <i class="fas fa-sign-out-alt"></i> 登出
                            </a>
                            <button type="button" id="exportPDFBtn" class="schedule-btn-export">
                                <i class="fa-solid fa-download me-1"></i>匯出 PDF
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <script>
            // 動態判斷 API 路徑
            const API_ROOT = location.pathname.includes('/pages/') ? '../api.php' : 'api.php';
            window.TEAM_APPLY_CONFIG = {
                u_ID: '<?= htmlspecialchars($u_ID) ?>',
                cohort_ID: <?= $cohort_ID ?? 'null' ?>,
                apiPath: API_ROOT
            };

            const exportBtn = document.getElementById('exportPDFBtn');
            if (exportBtn) exportBtn.disabled = true;
        </script>
        <script src="../js/team_apply.js"></script>
    </body>

    </html>