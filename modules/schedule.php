<?php
/**
 * 時程表管理後端 API 模組
 */

global $conn;
$p = $_POST;
$do = $_GET['do'] ?? '';
$u_ID = $_SESSION['u_ID'] ?? null;

// 檢查是否為科辦或主任 (role_ID=1, 2)
function checkOfficePermission() {
    global $conn;
    $u_ID = $_SESSION['u_ID'] ?? null;
    if (!$u_ID) {
        json_err('請先登入', 'NOT_LOGGED_IN', 401);
    }
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM userrolesdata 
        WHERE ur_u_ID = ? AND role_ID IN (1, 2) AND user_role_status = 1
    ");
    $stmt->execute([$u_ID]);
    if (!$stmt->fetchColumn()) {
        json_err('此功能僅限主任和科辦使用', 'NO_PERMISSION', 403);
    }
    return $u_ID;
}

switch ($do) {
    // 獲取所有團隊資料（包含成員和指導老師）
    case 'get_teams_schedule':
        try {
            checkOfficePermission();
            
            $cohort_ID = isset($_GET['cohort_ID']) && $_GET['cohort_ID'] !== '' ? (int)$_GET['cohort_ID'] : null;
            $group_ID = isset($_GET['group_ID']) && $_GET['group_ID'] !== '' ? (int)$_GET['group_ID'] : null;
            
            // 構建查詢條件
            $sql = "
                SELECT 
                    t.team_ID,
                    t.team_project_name,
                    t.cohort_ID,
                    t.group_ID,
                    g.group_name
                FROM teamdata t
                LEFT JOIN groupdata g ON t.group_ID = g.group_ID
                WHERE t.team_status = 1
            ";
            $params = [];
            
            if ($cohort_ID !== null) {
                $sql .= " AND t.cohort_ID = ?";
                $params[] = $cohort_ID;
            }
            
            if ($group_ID !== null) {
                $sql .= " AND t.group_ID = ?";
                $params[] = $group_ID;
            }
            
            // 預設排序：按類組排序（商務網站經營組 group_ID=2 在前，系統軟體開發組 group_ID=1 在後），再按團隊ID
            $sql .= " ORDER BY 
                        CASE 
                            WHEN t.group_ID = 2 THEN 0  -- 商務網站經營組優先
                            WHEN t.group_ID = 1 THEN 1  -- 系統軟體開發組（資訊組）其次
                            ELSE t.group_ID + 10  -- 其他類組按ID順序
                        END ASC,
                        t.team_ID ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 為每個團隊獲取成員和指導老師
            foreach ($teams as &$team) {
                $team_ID = $team['team_ID'];
                
                // 檢查 teammember 表結構（兼容兩種版本）
                $teamUserField = 'team_u_ID';
                $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $stmt->execute();
                if (!$stmt->fetch()) {
                    $teamUserField = 'u_ID';
                }
                
                // 獲取團隊成員（包含所有成員）
                $sql = "
                    SELECT DISTINCT
                        tm.{$teamUserField} as u_ID,
                        u.u_name
                    FROM teammember tm
                    INNER JOIN userdata u ON tm.{$teamUserField} = u.u_ID
                    WHERE tm.team_ID = ? AND tm.tm_status = 1
                    ORDER BY u.u_ID
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$team_ID]);
                $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 分離學生和指導老師
                $students = [];
                $teacher = null;
                
                foreach ($allMembers as $member) {
                    $u_ID = $member['u_ID'];
                    
                    // 檢查該用戶的角色
                    $roleSql = "
                        SELECT role_ID 
                        FROM userrolesdata 
                        WHERE ur_u_ID = ? AND user_role_status = 1
                    ";
                    $roleStmt = $conn->prepare($roleSql);
                    $roleStmt->execute([$u_ID]);
                    $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (in_array(4, $roles)) {
                        // 指導老師
                        if (!$teacher) {
                            $teacher = [
                                'u_ID' => $member['u_ID'],
                                'u_name' => $member['u_name']
                            ];
                        }
                    } elseif (in_array(6, $roles)) {
                        // 學生
                        $students[] = [
                            'u_ID' => $member['u_ID'],
                            'u_name' => $member['u_name']
                        ];
                    }
                }
                
                $team['students'] = $students;
                $team['teacher'] = $teacher;
                
                // 確保 students 是陣列（即使為空）
                if (!isset($team['students']) || !is_array($team['students'])) {
                    $team['students'] = [];
                }
                // 確保 teacher 是物件或 null
                if (!isset($team['teacher'])) {
                    $team['teacher'] = null;
                }
            }
            
            // 取消引用（避免後續問題）
            unset($team);
            
            json_ok(['teams' => $teams]);
        } catch (Throwable $e) {
            json_err('獲取團隊資料失敗：' . $e->getMessage());
        }
        break;

    // 獲取類組列表（根據屆別）
    case 'get_groups':
        try {
            checkOfficePermission();
            
            $cohort_ID = isset($_GET['cohort_ID']) && $_GET['cohort_ID'] !== '' ? (int)$_GET['cohort_ID'] : null;
            
            if ($cohort_ID) {
                // 獲取該屆別下的類組
                $sql = "
                    SELECT DISTINCT g.group_ID, g.group_name
                    FROM groupdata g
                    INNER JOIN teamdata t ON g.group_ID = t.group_ID
                    WHERE g.group_status = 1 AND t.team_status = 1 AND t.cohort_ID = ?
                    ORDER BY g.group_ID
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$cohort_ID]);
            } else {
                // 獲取所有啟用的類組
                $sql = "
                    SELECT group_ID, group_name
                    FROM groupdata
                    WHERE group_status = 1
                    ORDER BY group_ID
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute();
            }
            
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_ok(['groups' => $groups]);
        } catch (Throwable $e) {
            json_err('獲取類組列表失敗：' . $e->getMessage());
        }
        break;

    // 獲取時程表資訊
    case 'get_schedule_info':
        try {
            checkOfficePermission();
            
            $tinforma_ID = isset($_GET['tinforma_ID']) ? (int)$_GET['tinforma_ID'] : null;
            
            // 確保 timeinformadata 有 online_scoring_open 欄位，以便前端正確顯示線上評分狀態
            $hasScoringCol = false;
            try {
                $hasScoringCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'online_scoring_open'")->rowCount() > 0;
            } catch (Throwable $e) {
                $hasScoringCol = false;
            }
            if (!$hasScoringCol) {
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN online_scoring_open TINYINT(1) DEFAULT NULL COMMENT '線上評分：NULL=未設定，0=關閉，1=開放（設定後不可更改）'");
                } catch (Throwable $e) {
                    error_log('get_schedule_info 添加 online_scoring_open 欄位失敗: ' . $e->getMessage());
                }
            }
            
            if ($tinforma_ID) {
                // 獲取指定的時程表資訊
                $stmt = $conn->prepare("SELECT * FROM timeinformadata WHERE tinforma_ID = ?");
                $stmt->execute([$tinforma_ID]);
                $info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$info) {
                    json_err('找不到時程表資訊');
                }
                
                    // 獲取該時程表的所有團隊時程（去重：每個 team_ID 只保留一個）
                    $stmt = $conn->prepare("
                        SELECT 
                            td.*,
                            t.team_project_name
                        FROM timedata td
                        LEFT JOIN teamdata t ON td.team_ID = t.team_ID
                        WHERE td.tinforma_ID = ?
                        ORDER BY td.sort_no ASC, td.time_start_d ASC
                    ");
                    $stmt->execute([$tinforma_ID]);
                    $allSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // 去重：確保每個 team_ID 只出現一次（保留第一個）
                    $schedules = [];
                    $seenTeamIds = [];
                    foreach ($allSchedules as $schedule) {
                        $team_ID = $schedule['team_ID'];
                        if ($team_ID && !in_array($team_ID, $seenTeamIds)) {
                            $seenTeamIds[] = $team_ID;
                            $schedules[] = $schedule;
                        } elseif ($team_ID) {
                            error_log("發現重複的團隊時程，team_ID: {$team_ID}，已跳過重複項");
                        }
                    }
                    
                    json_ok([
                        'info' => $info,
                        'schedules' => $schedules
                    ]);
            } else {
                // 獲取最新的時程表資訊
                $stmt = $conn->prepare("
                    SELECT * FROM timeinformadata 
                    ORDER BY tinforma_create_d DESC 
                    LIMIT 1
                ");
                $stmt->execute();
                $info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($info) {
                    $tinforma_ID = $info['tinforma_ID'];
                    
                    // 獲取該時程表的所有團隊時程（去重：每個 team_ID 只保留一個）
                    $stmt = $conn->prepare("
                        SELECT 
                            td.*,
                            t.team_project_name
                        FROM timedata td
                        LEFT JOIN teamdata t ON td.team_ID = t.team_ID
                        WHERE td.tinforma_ID = ?
                        ORDER BY td.sort_no ASC, td.time_start_d ASC
                    ");
                    $stmt->execute([$tinforma_ID]);
                    $allSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // 去重：確保每個 team_ID 只出現一次（保留第一個）
                    $schedules = [];
                    $seenTeamIds = [];
                    foreach ($allSchedules as $schedule) {
                        $team_ID = $schedule['team_ID'];
                        if ($team_ID && !in_array($team_ID, $seenTeamIds)) {
                            $seenTeamIds[] = $team_ID;
                            $schedules[] = $schedule;
                        } elseif ($team_ID) {
                            error_log("發現重複的團隊時程，team_ID: {$team_ID}，已跳過重複項");
                        }
                    }
                    
                    json_ok([
                        'info' => $info,
                        'schedules' => $schedules
                    ]);
                } else {
                    json_ok([
                        'info' => null,
                        'schedules' => []
                    ]);
                }
            }
        } catch (Throwable $e) {
            json_err('獲取時程表資訊失敗：' . $e->getMessage());
        }
        break;

    // 保存時程表資訊
    case 'save_schedule_info':
        try {
            checkOfficePermission();
            
            $tinforma_ID = isset($p['tinforma_ID']) ? (int)$p['tinforma_ID'] : null;
            $tinforma_content = trim($p['tinforma_content'] ?? '');
            $tinforma_title = trim($p['tinforma_title'] ?? '');
            $cohort_ID = isset($p['cohort_ID']) && $p['cohort_ID'] !== '' ? (int)$p['cohort_ID'] : null;
            // 未傳時視為開啟（相容舊前端或漏傳）
            $online_scoring_open = isset($p['online_scoring_open']) ? (int)$p['online_scoring_open'] : 1;
            $online_scoring_open = (bool)$online_scoring_open;
            
            $is_new_schedule = !$tinforma_ID;
            
            // 檢查是否有標題欄位
            $hasTitleField = false;
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
                $hasTitleField = $checkStmt->rowCount() > 0;
            } catch (Throwable $e) {
                // 如果查詢失敗，假設沒有該欄位
                $hasTitleField = false;
            }
            
            // 如果沒有標題欄位，嘗試添加
            if (!$hasTitleField && $tinforma_title) {
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN tinforma_title VARCHAR(255) DEFAULT NULL COMMENT '時程表標題' AFTER tinforma_ID");
                    $hasTitleField = true;
                } catch (Throwable $e) {
                    // 如果添加失敗，忽略（可能是欄位已存在或其他錯誤）
                    error_log('添加標題欄位失敗: ' . $e->getMessage());
                }
            }
            
            // 檢查是否有線上評分欄位（NULL=尚未設定，0/1=已設定且不可再改）
            $hasScoringCol = false;
            try {
                $hasScoringCol = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'online_scoring_open'")->rowCount() > 0;
            } catch (Throwable $e) {
                $hasScoringCol = false;
            }
            if (!$hasScoringCol) {
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN online_scoring_open TINYINT(1) DEFAULT NULL COMMENT '線上評分：NULL=未設定，0=關閉，1=開放（設定後不可更改）'");
                    $hasScoringCol = true;
                } catch (Throwable $e) {
                    error_log('添加 online_scoring_open 欄位失敗: ' . $e->getMessage());
                }
            }
            
            $conn->beginTransaction();
            
            if ($tinforma_ID) {
                // 更新時一併寫入最後更新人與時間（供 integrate 列表「最後編輯人」顯示）
                $hasUpdateUserField = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_update_u_ID'")->rowCount() > 0;
                $hasUpdateD = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_update_d'")->rowCount() > 0;
                $updateExtra = '';
                if ($hasUpdateD) $updateExtra .= ", tinforma_update_d = NOW()";
                if ($hasUpdateUserField && $u_ID !== null && $u_ID !== '') $updateExtra .= ", tinforma_update_u_ID = ?";
                
                // 更新現有的時程表資訊（時程表編輯模式可隨時修改線上評分）
                if ($hasScoringCol) {
                    if ($hasTitleField) {
                        $stmt = $conn->prepare("
                            UPDATE timeinformadata 
                            SET tinforma_content = ?, tinforma_title = ?, online_scoring_open = ?" . $updateExtra . "
                            WHERE tinforma_ID = ?
                        ");
                        $args = [$tinforma_content, $tinforma_title, $online_scoring_open ? 1 : 0];
                        if ($hasUpdateUserField && $u_ID !== null && $u_ID !== '') $args[] = $u_ID;
                        $args[] = $tinforma_ID;
                        $stmt->execute($args);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE timeinformadata 
                            SET tinforma_content = ?, online_scoring_open = ?" . $updateExtra . "
                            WHERE tinforma_ID = ?
                        ");
                        $args = [$tinforma_content, $online_scoring_open ? 1 : 0];
                        if ($hasUpdateUserField && $u_ID !== null && $u_ID !== '') $args[] = $u_ID;
                        $args[] = $tinforma_ID;
                        $stmt->execute($args);
                    }
                    // 同步對應的審查建議表 sf_status（同屆別＋標題對應的建議表名稱）
                    $hasSfStatus = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_status'")->rowCount() > 0;
                    $hasSfCohort = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0;
                    if ($hasSfStatus && $tinforma_title !== '' && strtolower(trim($tinforma_title)) !== 'update') {
                        $suggest_title = str_replace(['程序表', '時程表'], '結果', $tinforma_title);
                        $cohort_for_sync = ($cohort_ID !== null && $cohort_ID !== '') ? (int)$cohort_ID : null;
                        if ($hasSfCohort && $cohort_for_sync !== null) {
                            $up = $conn->prepare("UPDATE suggestfrom SET sf_status = ?, sf_update_d = NOW() WHERE sf_name = ? AND sf_cohort = ?");
                            $up->execute([$online_scoring_open ? 1 : 0, $suggest_title, $cohort_for_sync]);
                        } else {
                            $up = $conn->prepare("UPDATE suggestfrom SET sf_status = ?, sf_update_d = NOW() WHERE sf_name = ?");
                            $up->execute([$online_scoring_open ? 1 : 0, $suggest_title]);
                        }
                    }
                } else {
                    if ($hasTitleField) {
                        $stmt = $conn->prepare("
                            UPDATE timeinformadata 
                            SET tinforma_content = ?, tinforma_title = ?" . $updateExtra . "
                            WHERE tinforma_ID = ?
                        ");
                        $args = [$tinforma_content, $tinforma_title];
                        if ($hasUpdateUserField && $u_ID !== null && $u_ID !== '') $args[] = $u_ID;
                        $args[] = $tinforma_ID;
                        $stmt->execute($args);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE timeinformadata 
                            SET tinforma_content = ?" . $updateExtra . "
                            WHERE tinforma_ID = ?
                        ");
                        $args = [$tinforma_content];
                        if ($hasUpdateUserField && $u_ID !== null && $u_ID !== '') $args[] = $u_ID;
                        $args[] = $tinforma_ID;
                        $stmt->execute($args);
                    }
                }
            } else {
                // 檢查是否有屆別欄位（用於寫入 tinforma_cohort）
                $hasCohortField = false;
                try {
                    $chk = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'");
                    $hasCohortField = $chk && $chk->rowCount() > 0;
                } catch (Throwable $e) {
                    $hasCohortField = false;
                }
                // 創建新的時程表資訊（首次儲存即寫入線上評分，之後不可改）
                if ($hasTitleField) {
                    if ($hasCohortField && $cohort_ID) {
                        if ($hasScoringCol) {
                            $stmt = $conn->prepare("
                                INSERT INTO timeinformadata (tinforma_content, tinforma_title, tinforma_cohort, online_scoring_open) 
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([$tinforma_content, $tinforma_title, $cohort_ID, $online_scoring_open ? 1 : 0]);
                        } else {
                            $stmt = $conn->prepare("
                                INSERT INTO timeinformadata (tinforma_content, tinforma_title, tinforma_cohort) 
                                VALUES (?, ?, ?)
                            ");
                            $stmt->execute([$tinforma_content, $tinforma_title, $cohort_ID]);
                        }
                    } else {
                        if ($hasScoringCol) {
                            $stmt = $conn->prepare("
                                INSERT INTO timeinformadata (tinforma_content, tinforma_title, online_scoring_open) 
                                VALUES (?, ?, ?)
                            ");
                            $stmt->execute([$tinforma_content, $tinforma_title, $online_scoring_open ? 1 : 0]);
                        } else {
                            $stmt = $conn->prepare("
                                INSERT INTO timeinformadata (tinforma_content, tinforma_title) 
                                VALUES (?, ?)
                            ");
                            $stmt->execute([$tinforma_content, $tinforma_title]);
                        }
                    }
                } else {
                    if ($hasScoringCol) {
                        $stmt = $conn->prepare("
                            INSERT INTO timeinformadata (tinforma_content, online_scoring_open) 
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$tinforma_content, $online_scoring_open ? 1 : 0]);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO timeinformadata (tinforma_content) 
                            VALUES (?)
                        ");
                        $stmt->execute([$tinforma_content]);
                    }
                }
                $tinforma_ID = $conn->lastInsertId();
            }
            
            $conn->commit();
            
            // 不論線上評分開或關，都自動建立審查建議表；sf_status：1=開放指導老師評分，0=不評分（仍建立建議表）
            // 標題取自時程表標題，並將「程序表」「時程表」換成「審查結果」
            // 只要目前沒有同標題（及屆別）的建議表，就會建立一次；之後再次儲存不會重複建立
            $suggest_created = false;
            $suggest_skip_reason = null; // 未建立時回傳原因，方便除錯
            $suggest_error = null;       // INSERT 失敗時的錯誤訊息
            $suggest_title = $tinforma_title;
            $suggest_title = str_replace(['程序表', '時程表'], '結果', $suggest_title);
            if ($tinforma_title === '') {
                $suggest_skip_reason = 'no_title';
            } elseif (strtolower(trim($tinforma_title)) === 'update') {
                $suggest_skip_reason = 'title_is_update';
            } elseif (!$u_ID) {
                $suggest_skip_reason = 'no_uid';
            }
            // 不論線上評分開或關：有標題、有登入、且尚無同標題同屆別建議表 → 就建立；sf_status=1 開放評分，=0 不評分
            if ($tinforma_title !== '' && $u_ID && strtolower(trim($tinforma_title)) !== 'update') {
                try {
                    $hasSfType = false;
                    $hasTinformaCol = false;
                    $hasSfCohort = false;
                    try {
                        if ($conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_type'")->rowCount() > 0) $hasSfType = true;
                        if ($conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_tinforma_ID'")->rowCount() > 0) $hasTinformaCol = true;
                        if ($conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_cohort'")->rowCount() > 0) $hasSfCohort = true;
                    } catch (Throwable $e) { /* 忽略 */ }
                    // 屆別：優先使用請求的 cohort_ID；若無則從剛建立的時程表讀取（確保建議表能建立）
                    $cohort_for_suggest = ($cohort_ID !== null && $cohort_ID !== '') ? (int)$cohort_ID : null;
                    if ($cohort_for_suggest === null && $tinforma_ID) {
                        try {
                            $hasTiCohort = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_cohort'")->rowCount() > 0;
                            if ($hasTiCohort) {
                                $r = $conn->prepare("SELECT tinforma_cohort FROM timeinformadata WHERE tinforma_ID = ?");
                                $r->execute([$tinforma_ID]);
                                $row = $r->fetch(PDO::FETCH_ASSOC);
                                if ($row && isset($row['tinforma_cohort']) && $row['tinforma_cohort'] !== null && $row['tinforma_cohort'] !== '') {
                                    $cohort_for_suggest = (int)$row['tinforma_cohort'];
                                }
                            }
                        } catch (Throwable $e) { /* 忽略 */ }
                    }
                    // 若 suggestfrom 尚無 sf_cohort 但有屆別可寫入，則自動加欄（方便老師端評分時段依屆別顯示）
                    if (!$hasSfCohort && $cohort_for_suggest !== null) {
                        try {
                            $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_cohort INT DEFAULT NULL COMMENT '屆別' AFTER sf_ID");
                            $hasSfCohort = true;
                        } catch (Throwable $e) {
                            error_log('suggestfrom 添加 sf_cohort 欄位失敗: ' . $e->getMessage());
                        }
                    }
                    $hasSfSentToOffice = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_sent_to_office'")->rowCount() > 0;
                    if (!$hasSfSentToOffice) {
                        try {
                            $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_sent_to_office TINYINT(1) DEFAULT 0 COMMENT '0=未送交科辦 1=已送交科辦'");
                            $hasSfSentToOffice = true;
                        } catch (Throwable $e) {
                            error_log('suggestfrom 添加 sf_sent_to_office 欄位失敗: ' . $e->getMessage());
                        }
                    }
                    // sf_status：0=不開放指導老師評分，1=開放指導老師評分（與時程表線上評分狀態一致）
                    $hasSfStatus = $conn->query("SHOW COLUMNS FROM suggestfrom LIKE 'sf_status'")->rowCount() > 0;
                    if (!$hasSfStatus) {
                        try {
                            $conn->exec("ALTER TABLE suggestfrom ADD COLUMN sf_status TINYINT(1) DEFAULT 0 COMMENT '0=不開放指導老師評分 1=開放指導老師評分'");
                            $hasSfStatus = true;
                        } catch (Throwable $e) {
                            error_log('suggestfrom 添加 sf_status 欄位失敗: ' . $e->getMessage());
                        }
                    }
                    // 重複檢查：同屆別＋同標題視為重複（使用 <=> 以支援 NULL）
                    $dupStmt = $hasSfCohort
                        ? $conn->prepare("SELECT sf_ID FROM suggestfrom WHERE sf_name = ? AND (sf_cohort <=> ?) LIMIT 1")
                        : $conn->prepare("SELECT sf_ID FROM suggestfrom WHERE sf_name = ? LIMIT 1");
                    if ($hasSfCohort) {
                        $dupStmt->execute([$suggest_title, $cohort_for_suggest]);
                    } else {
                        $dupStmt->execute([$suggest_title]);
                    }
                    if (!$dupStmt->fetch()) {
                        $insFields = ['sf_name', 'sf_u_ID', 'sf_created_d', 'sf_update_d'];
                        $insPlaceholders = ['?', '?', 'NOW()', 'NOW()'];
                        $insBind = [$suggest_title, $u_ID];
                        if ($hasSfType) {
                            $insFields[] = 'sf_type';
                            $insPlaceholders[] = '?';
                            $insBind[] = 'review';
                        }
                        if ($hasSfCohort) {
                            $insFields[] = 'sf_cohort';
                            $insPlaceholders[] = '?';
                            $insBind[] = $cohort_for_suggest;
                        }
                        if ($hasSfSentToOffice) {
                            $insFields[] = 'sf_sent_to_office';
                            $insPlaceholders[] = '?';
                            $insBind[] = 0;
                        }
                        if ($hasSfStatus) {
                            $insFields[] = 'sf_status';
                            $insPlaceholders[] = '?';
                            $insBind[] = $online_scoring_open ? 1 : 0;
                        }
                        // 不綁定時程表（不寫入 sf_tinforma_ID），僅依屆別與標題建立審查建議表
                        $sql = "INSERT INTO suggestfrom (" . implode(', ', $insFields) . ") VALUES (" . implode(', ', $insPlaceholders) . ")";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute($insBind);
                        $suggest_created = true;
                    } else {
                        $suggest_skip_reason = 'duplicate';
                    }
                } catch (Throwable $e) {
                    $suggest_skip_reason = 'insert_failed';
                    $suggest_error = $e->getMessage();
                    error_log('自動建立審查建議表失敗: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
                }
            }
            
            json_ok([
                'tinforma_ID' => $tinforma_ID,
                'message' => '時程表資訊已保存',
                'suggest_created' => $suggest_created,
                'suggest_skip_reason' => $suggest_skip_reason,
                'suggest_error' => $suggest_error
            ]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('保存時程表資訊失敗：' . $e->getMessage());
        }
        break;

    // 獲取時程表標題列表（根據屆別）
    case 'get_schedule_titles':
        try {
            checkOfficePermission();
            
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : null;
            
            if (!$cohort_ID || $cohort_ID <= 0) {
                // 返回空陣列而不是錯誤，避免前端顯示錯誤
                json_ok(['titles' => [], 'data' => []]);
            }
            
            // 檢查是否有標題欄位
            $hasTitleField = false;
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
                $hasTitleField = $checkStmt->rowCount() > 0;
            } catch (Throwable $e) {
                // 如果查詢失敗，嘗試直接添加欄位
                try {
                    $conn->exec("ALTER TABLE timeinformadata ADD COLUMN tinforma_title VARCHAR(255) DEFAULT NULL COMMENT '時程表標題'");
                    $hasTitleField = true;
                } catch (Throwable $e2) {
                    // 如果添加失敗，可能是欄位已存在或其他錯誤
                    $hasTitleField = false;
                }
            }
            
            // 獲取該屆別的所有時程表標題
            // 通過 timedata 關聯到 teamdata，再關聯到 cohort_ID
            if ($hasTitleField) {
                // 檢查是否有 tinforma_update_d 欄位
                $hasUpdateField = false;
                try {
                    $checkUpdateStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_update_d'");
                    $hasUpdateField = $checkUpdateStmt->rowCount() > 0;
                } catch (Throwable $e) {
                    $hasUpdateField = false;
                }
                
                // 先獲取該屆別的所有團隊 ID
                $teamStmt = $conn->prepare("SELECT team_ID FROM teamdata WHERE cohort_ID = ?");
                $teamStmt->execute([$cohort_ID]);
                $teamIds = $teamStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (empty($teamIds)) {
                    // 如果該屆別沒有團隊，返回空陣列
                    http_response_code(200);
                    json_ok(['titles' => [], 'data' => []]);
                    exit;
                }
                
                // 獲取這些團隊對應的 tinforma_ID
                $placeholders = str_repeat('?,', count($teamIds) - 1) . '?';
                $tinformaStmt = $conn->prepare("SELECT DISTINCT tinforma_ID FROM timedata WHERE team_ID IN ($placeholders)");
                if (!$tinformaStmt) {
                    throw new Exception('準備 tinforma_ID 查詢失敗: ' . implode(', ', $conn->errorInfo()));
                }
                $tinformaStmt->execute($teamIds);
                $tinformaIds = $tinformaStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (empty($tinformaIds)) {
                    // 如果沒有對應的時程表，返回空陣列
                    http_response_code(200);
                    json_ok(['titles' => [], 'data' => []]);
                    exit;
                }
                
                // 根據欄位是否存在選擇不同的 SQL
                $placeholders2 = str_repeat('?,', count($tinformaIds) - 1) . '?';
                if ($hasUpdateField) {
                    $sql = "
                        SELECT DISTINCT tinforma_title, tinforma_ID, 
                               COALESCE(tinforma_update_d, tinforma_create_d) as update_date
                        FROM timeinformadata
                        WHERE tinforma_ID IN ($placeholders2)
                          AND tinforma_title IS NOT NULL 
                          AND tinforma_title != ''
                        ORDER BY update_date DESC
                    ";
                } else {
                    $sql = "
                        SELECT DISTINCT tinforma_title, tinforma_ID, 
                               tinforma_create_d as update_date
                        FROM timeinformadata
                        WHERE tinforma_ID IN ($placeholders2)
                          AND tinforma_title IS NOT NULL 
                          AND tinforma_title != ''
                        ORDER BY update_date DESC
                    ";
                }
                
                try {
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($tinformaIds);
                    $titles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // 提取標題列表
                    $titleList = array_map(function($row) {
                        return $row['tinforma_title'];
                    }, $titles);
                    
                    // 去重
                    $titleList = array_values(array_unique($titleList));
                    
                    // 準備返回資料，確保有 update_date 欄位
                    $fileData = array_map(function($row) {
                        return [
                            'tinforma_title' => $row['tinforma_title'],
                            'tinforma_ID' => $row['tinforma_ID'],
                            'tinforma_update_d' => $row['update_date'] ?? null,
                            'tinforma_create_d' => $row['update_date'] ?? null
                        ];
                    }, $titles);
                    
                    json_ok(['titles' => $titleList, 'data' => $fileData]);
                } catch (Throwable $e) {
                    // SQL 執行錯誤，返回空陣列而不是錯誤
                    error_log('get_schedule_titles SQL 錯誤: ' . $e->getMessage());
                    json_ok(['titles' => [], 'data' => []]);
                }
            } else {
                // 如果沒有標題欄位，返回空陣列
                http_response_code(200);
                json_ok(['titles' => [], 'data' => []]);
                exit;
            }
        } catch (Throwable $e) {
            // 記錄錯誤但不中斷流程
            error_log('get_schedule_titles 錯誤: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            // 返回空陣列而不是錯誤，這樣前端可以正常顯示編輯界面
            http_response_code(200); // 確保返回 200 狀態碼
            json_ok(['titles' => [], 'data' => []]);
            exit; // 確保執行結束
        }
        break;

    // 根據標題獲取時程表資訊
    case 'get_schedule_by_title':
        try {
            checkOfficePermission();
            
            $cohort_ID = isset($_GET['cohort_ID']) ? (int)$_GET['cohort_ID'] : null;
            $title = isset($_GET['title']) ? trim($_GET['title']) : '';
            
            if (!$cohort_ID || !$title) {
                json_err('缺少必要參數');
            }
            
            // 檢查是否有標題欄位
            $hasTitleField = false;
            try {
                $checkStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_title'");
                $hasTitleField = $checkStmt->rowCount() > 0;
            } catch (Throwable $e) {
                $hasTitleField = false;
            }
            
            if (!$hasTitleField) {
                json_err('標題欄位不存在');
            }
            
            // 檢查是否有 tinforma_update_d 欄位
            $hasUpdateField = false;
            try {
                $checkUpdateStmt = $conn->query("SHOW COLUMNS FROM timeinformadata LIKE 'tinforma_update_d'");
                $hasUpdateField = $checkUpdateStmt->rowCount() > 0;
            } catch (Throwable $e) {
                $hasUpdateField = false;
            }
            
            // 獲取該標題的時程表資訊
            if ($hasUpdateField) {
                $sql = "
                    SELECT DISTINCT ti.*
                    FROM timeinformadata ti
                    INNER JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                    INNER JOIN teamdata t ON td.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND ti.tinforma_title = ?
                    ORDER BY COALESCE(ti.tinforma_update_d, ti.tinforma_create_d) DESC
                    LIMIT 1
                ";
            } else {
                $sql = "
                    SELECT DISTINCT ti.*
                    FROM timeinformadata ti
                    INNER JOIN timedata td ON ti.tinforma_ID = td.tinforma_ID
                    INNER JOIN teamdata t ON td.team_ID = t.team_ID
                    WHERE t.cohort_ID = ? 
                      AND ti.tinforma_title = ?
                    ORDER BY ti.tinforma_create_d DESC
                    LIMIT 1
                ";
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$cohort_ID, $title]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$info) {
                json_err('找不到該標題的時程表');
            }
            
            $tinforma_ID = $info['tinforma_ID'];
            
            // 獲取該時程表的所有團隊時程（去重：每個 team_ID 只保留一個）
            $stmt = $conn->prepare("
                SELECT 
                    td.*,
                    t.team_project_name
                FROM timedata td
                LEFT JOIN teamdata t ON td.team_ID = t.team_ID
                WHERE td.tinforma_ID = ?
                ORDER BY td.sort_no ASC, td.time_start_d ASC
            ");
            $stmt->execute([$tinforma_ID]);
            $allSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 去重：確保每個 team_ID 只出現一次（保留第一個）
            $schedules = [];
            $seenTeamIds = [];
            foreach ($allSchedules as $schedule) {
                $team_ID = $schedule['team_ID'];
                if ($team_ID && !in_array($team_ID, $seenTeamIds)) {
                    $seenTeamIds[] = $team_ID;
                    $schedules[] = $schedule;
                } elseif ($team_ID) {
                    error_log("發現重複的團隊時程，team_ID: {$team_ID}，已跳過重複項");
                }
            }
            
            json_ok([
                'info' => $info,
                'schedules' => $schedules
            ]);
        } catch (Throwable $e) {
            json_err('獲取時程表資訊失敗：' . $e->getMessage());
        }
        break;

    // 保存團隊時程
    case 'save_team_schedules':
        try {
            checkOfficePermission();
            
            $tinforma_ID = isset($p['tinforma_ID']) ? (int)$p['tinforma_ID'] : null;
            $schedules = json_decode($p['schedules'] ?? '[]', true);
            
            if (!$tinforma_ID) {
                json_err('缺少時程表資訊ID');
            }
            
            if (!is_array($schedules)) {
                json_err('時程資料格式錯誤');
            }
            
            $conn->beginTransaction();
            
            // 刪除該時程表的所有現有時程
            $stmt = $conn->prepare("DELETE FROM timedata WHERE tinforma_ID = ?");
            $stmt->execute([$tinforma_ID]);
            
            // 去重：確保每個 team_ID 只出現一次（保留最後一個）
            $uniqueSchedules = [];
            $teamIdMap = [];
            foreach ($schedules as $schedule) {
                $team_ID = (int)($schedule['team_ID'] ?? 0);
                if ($team_ID > 0) {
                    // 如果已存在該團隊，覆蓋（保留最新的）
                    $teamIdMap[$team_ID] = $schedule;
                }
            }
            $uniqueSchedules = array_values($teamIdMap);
            
            // 插入新的時程
            $stmt = $conn->prepare("
                INSERT INTO timedata (
                    tinforma_ID, team_ID, time_start_d, time_end_d, sort_no
                ) VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($uniqueSchedules as $schedule) {
                $team_ID = (int)($schedule['team_ID'] ?? 0);
                $time_start_d = $schedule['time_start_d'] ?? null;
                $time_end_d = $schedule['time_end_d'] ?? null;
                // 確保 sort_no 有值，如果沒有則使用數組索引+1
                $sort_no = isset($schedule['sort_no']) && $schedule['sort_no'] !== null ? (int)$schedule['sort_no'] : null;
                
                if ($team_ID > 0 && $time_start_d && $time_end_d) {
                    // 如果 sort_no 為 null，記錄警告但仍然保存
                    if ($sort_no === null) {
                        error_log("警告：團隊 {$team_ID} 的 sort_no 為 null，將使用預設值");
                    }
                    $stmt->execute([
                        $tinforma_ID,
                        $team_ID,
                        $time_start_d,
                        $time_end_d,
                        $sort_no
                    ]);
                } else {
                    error_log("跳過無效的時程資料：team_ID={$team_ID}, time_start_d=" . ($time_start_d ?? 'null') . ", time_end_d=" . ($time_end_d ?? 'null'));
                }
            }
            
            $conn->commit();
            
            json_ok(['message' => '團隊時程已保存']);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            json_err('保存團隊時程失敗：' . $e->getMessage());
        }
        break;

    default:
        json_err('Unknown action: ' . $do);
}

