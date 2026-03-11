    <!-- pages/meeting.php -->
    <?php
    session_start();
    require '../includes/pdo.php';

    // 檢查登入 (保持原邏輯)
    if (!isset($_SESSION['u_ID'])) {
        echo '<div class="alert alert-danger m-3">請先登入</div>';
        exit;
    }

    $u_ID = $_SESSION['u_ID'];
    $u_name = $_SESSION['u_name'] ?? '使用者';
    // 檢查使用者是否為 role=4（指導老師）
    $is_teacher = false;
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM userrolesdata WHERE ur_u_ID = ? AND role_ID = 4 AND user_role_status = 1");
        $stmt->execute([$u_ID]);
        $is_teacher = ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        $is_teacher = false;
    }
    // 取得 URL 指定的 team_ID / m_ID（若有）
    $selected_team = isset($_GET['team_ID']) ? (int)$_GET['team_ID'] : null;
    $selected_meeting_id = isset($_GET['m_ID']) ? (int)$_GET['m_ID'] : null;
    $selected_group_name = null;
    if ($selected_team) {
        try {
            $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(team_project_name),''), CONCAT('組別 ', team_ID)) AS group_name FROM teamdata WHERE team_ID = ? LIMIT 1");
            $stmt->execute([$selected_team]);
            $selected_group_name = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            $selected_group_name = null;
        }
    }

    // 取得此使用者「目前使用的屆別」優先使用 session，否則取最近一筆 enrollment
        $current_cohort = null;
        if (!empty($_SESSION['cohort_ID'])) {
            $current_cohort = (int)$_SESSION['cohort_ID'];
        } else {
            try {
                $stmt = $conn->prepare("SELECT cohort_ID FROM enrollmentdata WHERE enroll_u_ID = ? AND enroll_status=1 ORDER BY enroll_ID DESC LIMIT 1");
                $stmt->execute([$u_ID]);
                $current_cohort = (int)($stmt->fetchColumn() ?: 0);
                if ($current_cohort === 0) $current_cohort = null;
            } catch (Exception $e) {
                $current_cohort = null;
            }
        }
        $current_cohort_name = trim((string)($_SESSION['cohort_name'] ?? $_SESSION['year_label'] ?? ''));
        if ($current_cohort_name === '' && $current_cohort) {
            try {
                $stmt = $conn->prepare("SELECT cohort_name FROM cohortdata WHERE cohort_ID = ? LIMIT 1");
                $stmt->execute([$current_cohort]);
                $current_cohort_name = trim((string)$stmt->fetchColumn());
            } catch (Exception $e) {
                $current_cohort_name = '';
            }
            if ($current_cohort_name === '') {
                try {
                    $stmt = $conn->prepare("SELECT year_label FROM cohortdata WHERE cohort_ID = ? LIMIT 1");
                    $stmt->execute([$current_cohort]);
                    $current_cohort_name = trim((string)$stmt->fetchColumn());
                } catch (Exception $e) {
                    $current_cohort_name = '';
                }
            }
        }
        if ($current_cohort_name === '' && $current_cohort) {
            $current_cohort_name = '第 '.$current_cohort.' 屆';
        }
        if ($current_cohort_name === '') {
            $current_cohort_name = '未指定';
        }

        // 如果是指導老師且尚未選 team，僅顯示該使用者在此屆的「所屬團隊清單」（用 teammember 判斷），然後結束輸出
        if ($is_teacher && !$selected_team) {
            $teams = [];
            if ($current_cohort) {
                try {
                    // 偵測 teammember 表中使用者欄位名稱（相容 team_u_ID / u_ID 等差異）
                    $tm_col = 'team_u_ID';
                    // 如果 modules/team_apply.php 有 get_col_name 函式，使用它以確保相容性
                    if (function_exists('get_col_name')) {
                        $maybe = get_col_name('teammember', 'team_u_ID');
                        if (!empty($maybe)) $tm_col = $maybe;
                    }

                    // 僅顯示 teammember 中有此指導老師的組別（teamdata + teammember）
                    $memberSql = "SELECT DISTINCT t.team_ID, t.team_project_name
                                  FROM teammember tm
                                  JOIN teamdata t ON tm.team_ID = t.team_ID
                                  WHERE tm.".$tm_col." = ? AND t.cohort_ID = ? AND t.team_status = 1
                                    AND (tm.tm_status IS NULL OR tm.tm_status = 1)";

                    $teams = [];
                    $tstmt = $conn->prepare($memberSql);
                    $tstmt->execute([$u_ID, $current_cohort]);
                    $teams = $tstmt->fetchAll(PDO::FETCH_ASSOC);

                    // ===== 會議總覽補充資料 =====
                    if (!empty($teams)) {
                        $teamIds = array_values(array_map(static fn($r) => (int)$r['team_ID'], $teams));
                        $idPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));

                        // 1) 會議次數
                        $meetingCountMap = [];
                        $qMeeting = $conn->prepare("
                            SELECT m_team_ID, COUNT(*) AS cnt
                            FROM meetingdata
                            WHERE m_team_ID IN ($idPlaceholders)
                              AND (m_status IS NULL OR m_status = 1)
                            GROUP BY m_team_ID
                        ");
                        $qMeeting->execute($teamIds);
                        foreach ($qMeeting->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                            $meetingCountMap[(int)$mr['m_team_ID']] = (int)$mr['cnt'];
                        }

                        // 2) 最新會議出席率（優先讀 m_check.attendance_rate，無則由 status_map 計算）
                        $attendanceRateMap = [];
                        $qLatest = $conn->prepare("
                            SELECT md.m_team_ID, md.m_check
                            FROM meetingdata md
                            INNER JOIN (
                                SELECT m_team_ID, MAX(m_ID) AS max_mid
                                FROM meetingdata
                                WHERE m_team_ID IN ($idPlaceholders)
                                  AND (m_status IS NULL OR m_status = 1)
                                GROUP BY m_team_ID
                            ) x ON x.max_mid = md.m_ID
                        ");
                        $qLatest->execute($teamIds);
                        foreach ($qLatest->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                            $tid = (int)$ar['m_team_ID'];
                            $raw = (string)($ar['m_check'] ?? '');
                            if ($raw === '') {
                                $attendanceRateMap[$tid] = null;
                                continue;
                            }
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded) && isset($decoded['attendance_rate']) && $decoded['attendance_rate'] !== null) {
                                $attendanceRateMap[$tid] = (int)$decoded['attendance_rate'];
                                continue;
                            }
                            $statusMap = [];
                            if (is_array($decoded) && isset($decoded['status_map']) && is_array($decoded['status_map'])) {
                                $statusMap = $decoded['status_map'];
                            } elseif (is_array($decoded)) {
                                foreach ($decoded as $k => $v) {
                                    if (in_array($v, ['ok', 'no'], true)) $statusMap[(string)$k] = $v;
                                }
                            }
                            $ok = 0;
                            $no = 0;
                            foreach ($statusMap as $v) {
                                if ($v === 'ok') $ok++;
                                elseif ($v === 'no') $no++;
                            }
                            $total = $ok + $no;
                            $attendanceRateMap[$tid] = $total > 0 ? (int)round(($ok / $total) * 100) : null;
                        }

                        // 3) 成員名單（頭像群組 + Tooltip）
                        $memberMap = [];
                        $qMember = $conn->prepare("
                            SELECT tm.team_ID, COALESCE(NULLIF(TRIM(u.u_name),''), tm.$tm_col) AS u_name
                            FROM teammember tm
                            LEFT JOIN userdata u ON u.u_ID = tm.$tm_col
                            WHERE tm.team_ID IN ($idPlaceholders)
                              AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                            ORDER BY tm.team_ID, u_name
                        ");
                        $qMember->execute($teamIds);
                        foreach ($qMember->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $tid = (int)$row['team_ID'];
                            if (!isset($memberMap[$tid])) $memberMap[$tid] = [];
                            $memberMap[$tid][] = (string)$row['u_name'];
                        }

                        // 寫回 teams
                        foreach ($teams as &$t) {
                            $tid = (int)$t['team_ID'];
                            $names = $memberMap[$tid] ?? [];
                            $t['member_names'] = $names;
                            $t['member_count'] = count($names);
                            $t['meeting_count'] = (int)($meetingCountMap[$tid] ?? 0);
                                $t['attendance_rate'] = $attendanceRateMap[$tid] ?? null;
                        }
                        unset($t);
                    }

                } catch (Exception $e) {
                    $teams = [];
                }
            }

            // 輸出簡潔頁面（只有團隊列表）
            ?>
              
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/meeting.css?v=<?= time() ?>">
    <title>選擇組別 - 會議統整</title>


    <div>
            <h3 class="meeting-overview-title">會議總覽</h3>

        <?php if (empty($teams)): ?>
            <p class="meeting-overview-empty">找不到您在本屆的組別（可能尚未加入任何組別）。</p>
        <?php else: ?>
            <section class="team-overview-card">
                <table class="team-overview-table">
                    <thead>
                        <tr>
                            <th style="width:10%;">屆別</th>
                            <th style="width:23%;">專題名稱</th>
                            <th style="width:37%;">成員</th>
                            <th style="width:120px;">會議次數</th>
                            <th style="width:200px;">出席率</th>
                            <th style="width:180px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teams as $t): ?>
                        <?php
                            $memberNames = is_array($t['member_names'] ?? null) ? $t['member_names'] : [];
                            $memberCount = (int)($t['member_count'] ?? count($memberNames));
                            $memberTitle = !empty($memberNames) ? implode('、', $memberNames) : '尚無成員資料';
                            $memberInline = !empty($memberNames) ? implode('、', $memberNames) : '尚無成員';
                            $meetingCount = (int)($t['meeting_count'] ?? 0);
                            $rate = isset($t['attendance_rate']) && $t['attendance_rate'] !== null ? (int)$t['attendance_rate'] : null;
                            $rateWidth = $rate !== null ? max(0, min(100, $rate)) : 0;
                        ?>
                        <tr>
                            <td><span class="col-cohort"><?= htmlspecialchars($current_cohort_name) ?></span></td>
                            <td><span class="col-project"><?= htmlspecialchars($t['team_project_name'] ?: ('組別 '.(int)$t['team_ID'])) ?></span></td>
                            <td title="<?= htmlspecialchars($memberTitle) ?>">
                                <div class="member-names"><?= htmlspecialchars($memberInline) ?></div>
                                <div class="member-hint">共 <?= $memberCount ?> 人</div>
                            </td>
                            <td><span class="meeting-count-text"><?= $meetingCount ?> 次</span></td>
                            <td>
                                <div class="rate-wrap">
                                    <div class="rate-bar"><span style="width:<?= $rateWidth ?>%;"></span></div>
                                    <div class="rate-text"><?= $rate !== null ? ($rate.'%') : '尚無資料' ?></div>
                                </div>
                            </td>
                            <td style="text-align:right;">
                                <a class="btn btn-sm btn-primary ajax-link" href="#pages/meeting.php?team_ID=<?= (int)$t['team_ID'] ?>" style="margin-right:6px; padding:6px 10px; border-radius:6px;">進入</a>
                                <a class="btn btn-sm btn-outline-secondary ajax-link" href="#pages/meeting_list.php?team_ID=<?= (int)$t['team_ID'] ?>" style="padding:6px 10px; border-radius:6px;">紀錄</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </div>
         
            <?php
            exit;
        }
    ?>
    <link rel="stylesheet" href="css/meeting.css?v=<?= time() ?>">
    <!-- Processing Overlay -->
    <div id="busyOverlay" class="busy-overlay" style="display:none;">
        <div class="busy-card">
            <div class="busy-spinner"></div>
            <div class="busy-title" id="busyTitle">處理中...</div>
            <div class="busy-desc" id="busyDesc">請稍候，不要關閉頁面</div>
        </div>
    </div>

    <div class="app-container">

        <aside class="app-sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <h1 class="brand-title">會議統整</h1>
                    <div class="meeting-context">
                        <?php if (!empty($selected_team)): ?>
                            <span class="meeting-context-chip">組別 <?= htmlspecialchars($selected_group_name ?: ('#'.(int)$selected_team)) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="attendance-panel">
                    <div class="attendance-card-header">
                        <div class="attendance-card-header-left">
                        <h4 class="attendance-card-title">會議出席紀錄</h4>
                            <?php if (!empty($selected_team) && empty($selected_meeting_id)): ?>
                            <a href="#pages/meeting_list.php?team_ID=<?= (int)$selected_team ?>" class="attendance-add-meeting-btn ajax-link" title="新增會議">
                                <i class="fa-solid fa-plus"></i> 新增會議
                            </a>
                            <?php endif; ?>
                        </div>
                        <div id="attendanceStats" class="attendance-stats"></div>
                    </div>
                    <div id="attendanceList" class="attendance-list-grid">
                        <!-- 載入中... -->
                    </div>
                    <div id="historyBody" class="attendance-aux-content" style="display:block;">
                        <div class="history-panel">
                        <div class="history-filters">
                            <input id="historyKeyword" class="form-control history-search" placeholder="會議標題搜尋…" />
                            <div class="history-date-row">
                                <input id="historyDateFrom" type="date" class="form-control" title="起始日期" />
                                <span class="history-date-sep">至</span>
                                <input id="historyDateTo" type="date" class="form-control" title="結束日期" />
                            </div>
                        </div>

                        <div id="meetingHistory" class="history-list history-timeline">
                            <p class="empty-state" style="font-size:35px;">尚無記錄</p>
                        </div>

                        <div id="meetingFiles" class="history-list" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>

        </aside>

        <main class="app-main">
            <header class="doc-header">
                <div class="doc-meta">
                    <!-- ✅ 這個就是你要的「會議紀錄標題」：固定位置、直接點了改 -->
                    <span id="meetingTitle"
                        class="meeting-title"
                        contenteditable="true"
                        spellcheck="false"
                        data-placeholder="輸入會議標題（例如：會議紀錄 2026-02-14）">
                        會議紀錄 <?= date('Y-m-d') ?>
                    </span>
                    <div class="doc-context-row">
                        <span class="date-badge"><?= date('Y/m/d') ?></span>
                        <span class="user-badge"><?= htmlspecialchars($u_name) ?></span>
                        <?php if (!empty($selected_team)): ?>
                            <span class="user-badge">組別：<?= htmlspecialchars($selected_group_name ?: ('#'.(int)$selected_team)) ?></span>
                        <?php endif; ?>
                        <span class="doc-context-note">內容工作區</span>
                    </div>
                </div>

                <div class="doc-actions">
                    <?php if (!empty($selected_team) && empty($selected_meeting_id)): ?>
                        <a href="#pages/meeting_list.php?team_ID=<?= (int)$selected_team ?>" class="action-btn ajax-link" title="新增會議" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                            <i class="fa-solid fa-plus"></i> 新增會議
                        </a>
                    <?php endif; ?>
                    <?php if ($is_teacher): ?>
                    <button class="action-btn primary" id="btnConfirmMeeting" title="確認此次會議">
                        確認此次會議
                    </button>
                    <?php endif; ?>
                    <details class="action-more">
                        <summary class="action-btn" title="更多操作">更多</summary>
                        <div class="action-more-menu">
                            <button type="button" class="action-more-item" id="btnClear" title="清除">清除內容</button>
                        </div>
                    </details>
                </div>
            </header>


            <?php if ($is_teacher && !$selected_team): ?>
                <div class="selector-panel" style="padding:24px;">
                    <h3>請先選擇屆別與組別（您為指導老師）</h3>
                    <?php if (empty($cohorts)): ?>
                        <p>找不到可管理的屆別或組別。</p>
                    <?php else: ?>
                        <?php foreach ($cohorts as $c): ?>
                            <div style="margin-bottom:18px; padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; color:#111;">
                                <div style="font-weight:700; margin-bottom:8px;">
                                    <?= htmlspecialchars($c['year_label'] ?? ($c['cohort_name'] ?? '第 '.$c['cohort_ID'].' 屆')) ?>
                                </div>
                                <?php if (empty($c['teams'])): ?>
                                    <div style="color:#666">該屆目前無組別。</div>
                                <?php else: ?>
                                    <table style="width:100%; border-collapse:collapse;">
                                        <thead>
                                            <tr>
                                                <th style="text-align:left; padding:6px; border-bottom:1px solid #eee">組別 ID</th>
                                                <th style="text-align:left; padding:6px; border-bottom:1px solid #eee">專題名稱</th>
                                                <th style="text-align:right; padding:6px; border-bottom:1px solid #eee">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($c['teams'] as $t): ?>
                                                <tr>
                                                    <td style="padding:8px; border-bottom:1px solid #f5f5f5; vertical-align:middle; width:100px"><?= (int)$t['team_ID'] ?></td>
                                                    <td style="padding:8px; border-bottom:1px solid #f5f5f5;"><?= htmlspecialchars($t['team_project_name'] ?: '組別 '.(int)$t['team_ID']) ?></td>
                                                    <td style="padding:8px; border-bottom:1px solid #f5f5f5; text-align:right;">
                                                        <a class="btn btn-sm btn-primary ajax-link" href="#pages/meeting.php?team_ID=<?= (int)$t['team_ID'] ?>">選擇</a>
                                                        <a class="btn btn-sm btn-secondary ajax-link" href="#pages/meeting_list.php?team_ID=<?= (int)$t['team_ID'] ?>" style="margin-left:8px;">會議列表</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="editor-wrapper">
                    <div class="editor-paper" id="notebookContent">
                        <div class="content-panel-head">
                            <h4 class="content-panel-title">會議內容</h4>
                            <div class="content-panel-status">
                                狀態：<span id="contentSaveStatus">草稿</span>
                                <span class="content-panel-status-note">（點「編輯」進入編輯，點「完成」儲存）</span>
                            </div>
                        </div>
                        <div class="content-type-tabs">
                            <button type="button" class="content-type-tab is-active" data-kind="note">打字紀錄 <span class="content-type-count">0</span></button>
                            <button type="button" class="content-type-tab" data-kind="text">文字檔 <span class="content-type-count">0</span></button>
                            <button type="button" class="content-type-tab" data-kind="image">圖片 OCR <span class="content-type-count">0</span></button>
                            <button type="button" class="content-type-tab" data-kind="audio">語音轉錄 <span class="content-type-count">0</span></button>
                            <button type="button" class="content-type-tab" data-kind="summary">AI 摘要 <span class="content-type-count">0</span></button>
                        </div>
                        <div id="noteEmptyHint" class="note-empty-hint">此組別目前尚無打字紀錄，點「編輯」後可直接在下方打字紀錄，完成後點「完成」儲存。</div>
                        <div class="editor-content"
                            id="meetingText"
                            contenteditable="true"
                            data-placeholder="在此輸入會議內容，或使用左側工具上傳錄音..."></div>
                    </div>

                    <!-- 隱藏的上傳 / 錄音控制元件，提供 JS 綁定使用 -->
                    <div style="display:none;">
                        <!-- 文字檔上傳 -->
                        <input type="file" id="textInput" multiple>
                        <div id="uploadTextZone"></div>

                        <!-- 圖片上傳（圖片 OCR） -->
                        <input type="file" id="imageInput" accept="image/*" multiple>
                        <div id="uploadImageZone"></div>

                        <!-- 音檔上傳（語音轉錄） -->
                        <input type="file" id="audioInput" accept="audio/*" multiple>
                        <div id="uploadAudioZone"></div>

                        <!-- 系統錄音控制（開始 / 停止） -->
                        <button type="button" id="recordBtn"></button>
                        <button type="button" id="stopRecordBtn"></button>
                        <span id="recordingStatus"></span>
                        <span id="recordingTime"></span>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="js/meeting.js?v=<?= time() ?>"></script>
    <script>
        window.MEETING_USER = {
            u_ID: '<?= htmlspecialchars($u_ID, ENT_QUOTES, 'UTF-8') ?>',
            u_name: '<?= htmlspecialchars($u_name, ENT_QUOTES, 'UTF-8') ?>',
            is_teacher: <?= $is_teacher ? 'true' : 'false' ?>
        };
        window.MEETING_TEAM = <?= json_encode($selected_team ?: null) ?>;
    </script>