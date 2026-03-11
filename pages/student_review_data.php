<?php
session_start();
require '../includes/pdo.php';

// 設置時區為台北時間
date_default_timezone_set('Asia/Taipei');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['u_ID'])) {
    echo json_encode(['success' => false, 'error' => '尚未登入']);
    exit;
}

$uid = strval($_SESSION['u_ID']);
$action = $_GET['action'] ?? '';

// 檢查欄位名稱（兼容不同版本的資料表結構）
function columnExists(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$teamUserField = columnExists($conn, 'teammember', 'team_u_ID') ? 'team_u_ID' : 'u_ID';
$userRoleUidField = columnExists($conn, 'userrolesdata', 'ur_u_ID') ? 'ur_u_ID' : 'u_ID';

switch ($action) {
    case 'get_all_periods':
        try {
            // 獲取當前用戶的團隊ID
            $my_team_ID = null;
            try {
                $stmt = $conn->prepare("
                    SELECT team_ID 
                    FROM teammember 
                    WHERE {$teamUserField} = ? 
                    AND tm_status = 1
                    ORDER BY tm_updated_d DESC 
                    LIMIT 1
                ");
                $stmt->execute([$uid]);
                $my_team_ID = $stmt->fetchColumn();
            } catch (Throwable $e) {
                $my_team_ID = null;
            }
            
            // 檢查表名（可能是 perioddata 或其他）
            $tableName = 'perioddata';
            try {
                $testStmt = $conn->query("SHOW TABLES LIKE 'perioddata'");
                if ($testStmt->rowCount() === 0) {
                    // 嘗試其他可能的表名
                    $testStmt2 = $conn->query("SHOW TABLES LIKE 'ReviewPeriods'");
                    if ($testStmt2->rowCount() > 0) {
                        $tableName = 'ReviewPeriods';
                    } else {
                        $tableName = 'reviewperiods';
                    }
                }
            } catch (Exception $e) {
                $tableName = 'perioddata';
            }

            // 檢查欄位名稱
            $hasPeriodType = columnExists($conn, $tableName, 'period_type');
            $hasPeMode = columnExists($conn, $tableName, 'pe_mode');
            $hasPeStatus = columnExists($conn, $tableName, 'pe_status');
            $hasIsActive = columnExists($conn, $tableName, 'is_active');
            $hasCreatedUserId = columnExists($conn, $tableName, 'pe_created_u_ID');
            $hasPeTargetId = columnExists($conn, $tableName, 'pe_target_ID');
            
            $fields = "p.period_ID, p.period_title, p.period_start_d, p.period_end_d";
            if ($hasPeriodType) {
                $fields .= ', p.period_type';
            } else if ($hasPeMode) {
                $fields .= ', p.pe_mode as period_type'; // 統一使用 period_type 名稱
            }
            if ($hasPeTargetId) {
                $fields .= ', p.pe_target_ID';
            }
            if ($hasPeStatus) {
                $fields .= ', p.pe_status as is_active';
            } else if ($hasIsActive) {
                $fields .= ', p.is_active';
            } else {
                $fields .= ', 1 as is_active'; // 預設值
            }
            
            // 如果有建立者欄位，加入建立者姓名
            if ($hasCreatedUserId) {
                $fields .= ', p.pe_created_u_ID, COALESCE(u.u_name, p.pe_created_u_ID, \'\') as creator_name';
            } else {
                $fields .= ', NULL as creator_name';
            }

            // 先獲取所有時段
            if ($hasCreatedUserId) {
                $sql = "SELECT {$fields}
                        FROM {$tableName} p
                        LEFT JOIN userdata u ON u.u_ID = p.pe_created_u_ID";
            } else {
                $sql = "SELECT {$fields}
                        FROM {$tableName} p";
            }
            
            $stmt = $conn->query($sql);
            $allPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 過濾：只返回需要當前用戶評分的時段
            $filteredPeriods = [];
            
            foreach ($allPeriods as $period) {
                $period_type = isset($period['period_type']) ? $period['period_type'] : 'in';
                $period_ID = $period['period_ID'];
                $shouldShow = false;
                
                if ($period_type === 'in') {
                    // 團隊內互評：檢查該時段是否指定了當前用戶的團隊
                    if ($my_team_ID !== false && $my_team_ID !== null) {
                        // 檢查 petargetdata 表中是否有該時段指定該團隊的記錄
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) 
                            FROM petargetdata
                            WHERE period_ID = ? AND pe_team_ID = ?
                        ");
                        $stmt->execute([$period_ID, $my_team_ID]);
                        $isTeamSpecified = $stmt->fetchColumn() > 0;
                        
                        if ($isTeamSpecified) {
                            // 如果該團隊被指定，再檢查該團隊是否有其他成員（排除自己）
                            $stmt = $conn->prepare("
                                SELECT COUNT(*) 
                                FROM teammember tm
                                INNER JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
                                    AND ur.role_ID = 6
                                    AND ur.user_role_status = 1
                                WHERE tm.team_ID = ? 
                                    AND tm.{$teamUserField} != ?
                                    AND tm.tm_status = 1
                            ");
                            $stmt->execute([$my_team_ID, $uid]);
                            $otherMembersCount = $stmt->fetchColumn();
                            $shouldShow = $otherMembersCount > 0;
                        } else {
                            // 如果該團隊沒有被指定，不顯示該時段
                            $shouldShow = false;
                        }
                    }
                } else if ($period_type === 'cross') {
                    // 團隊間互評：檢查用戶的團隊是否被設定為評分者
                    $pe_target_ID = isset($period['pe_target_ID']) ? $period['pe_target_ID'] : 'ALL';
                    
                    if ($my_team_ID !== false && $my_team_ID !== null) {
                        // 解析 pe_target_ID（可能是 JSON 格式，包含 assign 和 receive）
                        $assignTeams = []; // 評分者團隊
                        
                        if ($pe_target_ID !== 'ALL' && !empty($pe_target_ID)) {
                            // 嘗試解析 JSON
                            if (strpos($pe_target_ID, '{') === 0) {
                                $targetData = json_decode($pe_target_ID, true);
                                if ($targetData && isset($targetData['assign'])) {
                                    $assignTeams = array_map('intval', (array)$targetData['assign']);
                                }
                            }
                        }
                        
                        if (count($assignTeams) > 0) {
                            // 如果有 assign 設定，檢查當前團隊是否在列表中
                            $shouldShow = in_array($my_team_ID, $assignTeams);
                        } else {
                            // 如果沒有 assign 設定，檢查 petargetdata 表
                            // 如果 petargetdata 中有被評分團隊的記錄，表示可能需要評分
                            // 這裡我們假設如果有 petargetdata 記錄，所有團隊都可以評分
                            $stmt = $conn->prepare("
                                SELECT COUNT(*) 
                                FROM petargetdata
                                WHERE period_ID = ? AND pe_team_ID IS NOT NULL
                            ");
                            $stmt->execute([$period_ID]);
                            $hasTargetTeams = $stmt->fetchColumn() > 0;
                            $shouldShow = $hasTargetTeams;
                        }
                    }
                }
                
                if ($shouldShow) {
                    $filteredPeriods[] = $period;
                }
            }
            
            // 排序：用結束日期排序，已經結束的排在最下方
            usort($filteredPeriods, function($a, $b) {
                $aEnded = strtotime($a['period_end_d']) < time();
                $bEnded = strtotime($b['period_end_d']) < time();
                if ($aEnded != $bEnded) {
                    return $aEnded ? 1 : -1; // 未結束的在前
                }
                return strtotime($b['period_end_d']) - strtotime($a['period_end_d']); // 按結束日期降序
            });
            
            echo json_encode($filteredPeriods, JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(200);
            echo json_encode(['error' => '取得時段失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'get_teams_to_review':
        try {
            $period_ID = isset($_GET['period_ID']) ? (int)$_GET['period_ID'] : 0;

            if ($period_ID <= 0) {
                echo json_encode(['error' => '缺少時段ID'], JSON_UNESCAPED_UNICODE);
                break;
            }

            // 獲取評分者自己的團隊ID
            $my_team_ID = null;
            try {
                $stmt = $conn->prepare("
                    SELECT team_ID 
                    FROM teammember 
                    WHERE {$teamUserField} = ? 
                    AND tm_status = 1
                    ORDER BY tm_updated_d DESC 
                    LIMIT 1
                ");
                $stmt->execute([$uid]);
                $my_team_ID = $stmt->fetchColumn();
            } catch (Throwable $e) {
                $my_team_ID = null;
            }

            // 檢查表名
            $tableName = 'perioddata';
            try {
                $testStmt = $conn->query("SHOW TABLES LIKE 'perioddata'");
                if ($testStmt->rowCount() === 0) {
                    $testStmt2 = $conn->query("SHOW TABLES LIKE 'ReviewPeriods'");
                    if ($testStmt2->rowCount() > 0) {
                        $tableName = 'ReviewPeriods';
                    } else {
                        $tableName = 'reviewperiods';
                    }
                }
            } catch (Exception $e) {
                $tableName = 'perioddata';
            }

            // 獲取時段信息
            $hasPeriodType = columnExists($conn, $tableName, 'period_type');
            $hasPeTargetId = columnExists($conn, $tableName, 'pe_target_ID');
            $hasPeMode = columnExists($conn, $tableName, 'pe_mode');
            
            $fields = 'period_ID, period_title, period_start_d, period_end_d';
            if ($hasPeriodType) {
                $fields .= ', period_type';
            }
            if ($hasPeTargetId) {
                $fields .= ', pe_target_ID';
            }
            if ($hasPeMode) {
                $fields .= ', pe_mode';
            }

            $stmt = $conn->prepare("
                SELECT {$fields}
                FROM {$tableName}
                WHERE period_ID = ?
            ");
            $stmt->execute([$period_ID]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$period) {
                echo json_encode(['error' => '找不到時段'], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // 檢查時段是否已開始（使用完整的日期時間，包含時分秒）
            $periodStartDate = isset($period['period_start_d']) ? new DateTime($period['period_start_d']) : null;
            $now = new DateTime();
            $isPeriodNotStarted = false;
            if ($periodStartDate) {
                // 使用實際的開始時間，不修改時分秒
                $isPeriodNotStarted = $now < $periodStartDate;
            }
            
            // 檢查時段是否已結束（使用完整的日期時間，包含時分秒）
            $periodEndDate = isset($period['period_end_d']) ? new DateTime($period['period_end_d']) : null;
            $isPeriodEnded = false;
            if ($periodEndDate) {
                // 使用實際的結束時間，不修改時分秒
                $isPeriodEnded = $now > $periodEndDate;
            }

            $teams = [];
            $members = [];

            // 根據模式獲取需要被評分的團隊
            // 優先使用 period_type，如果沒有則使用 pe_mode，都沒有則預設為 'in'
            $period_type = isset($period['period_type']) ? $period['period_type'] : 
                          (isset($period['pe_mode']) ? $period['pe_mode'] : 'in');
            $pe_mode = $period_type; // 統一使用 pe_mode 變數名稱

            if ($pe_mode === 'in' && $my_team_ID !== false && $my_team_ID !== null) {
                // 團隊內互評：獲取自己團隊的成員（排除自己）
                $stmt = $conn->prepare("
                    SELECT 
                        tm.{$teamUserField} AS u_ID,
                        COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name,
                        ud.u_img,
                        t.team_ID,
                        t.team_project_name
                    FROM teammember tm
                    INNER JOIN teamdata t ON t.team_ID = tm.team_ID
                    INNER JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
                        AND ur.role_ID = 6
                        AND ur.user_role_status = 1
                    LEFT JOIN userdata ud ON ud.u_ID = tm.{$teamUserField}
                    WHERE tm.team_ID = ?
                        AND tm.{$teamUserField} != ?
                        AND tm.tm_status = 1
                    ORDER BY tm.{$teamUserField} ASC
                ");
                $stmt->execute([$my_team_ID, $uid]);
                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 獲取團隊信息
                if (count($members) > 0) {
                    $stmt = $conn->prepare("
                        SELECT team_ID, team_project_name
                        FROM teamdata
                        WHERE team_ID = ?
                    ");
                    $stmt->execute([$my_team_ID]);
                    $team = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($team) {
                        $teams[] = $team;
                    }
                }
            } else if ($pe_mode === 'cross' && $my_team_ID !== false && $my_team_ID !== null) {
                // 團隊間互評：獲取其他團隊的成員
                // 需要檢查評分關係：只有當當前用戶的團隊被設定為可以評分某些團隊時，才能看到那些團隊
                
                $pe_target_ID = isset($period['pe_target_ID']) ? $period['pe_target_ID'] : 'ALL';
                
                // 解析 pe_target_ID（可能是 JSON 格式，包含 assign 和 receive）
                $assignTeams = []; // 評分者團隊
                $receiveTeams = []; // 被評分團隊
                
                if ($pe_target_ID !== 'ALL' && !empty($pe_target_ID)) {
                    // 嘗試解析 JSON
                    if (strpos($pe_target_ID, '{') === 0) {
                        $targetData = json_decode($pe_target_ID, true);
                        if ($targetData) {
                            if (isset($targetData['assign'])) {
                                $assignTeams = array_map('intval', (array)$targetData['assign']);
                            }
                            if (isset($targetData['receive'])) {
                                $receiveTeams = array_map('intval', (array)$targetData['receive']);
                            }
                        }
                    } else {
                        // 逗號分隔的團隊ID（可能是被評分團隊）
                        $receiveTeams = array_filter(array_map('intval', explode(',', $pe_target_ID)));
                    }
                }
                
                // 從 petargetdata 表獲取被評分團隊（這些是可能被評分的團隊）
                $stmt = $conn->prepare("
                    SELECT DISTINCT pe_team_ID
                    FROM petargetdata
                    WHERE period_ID = ? AND pe_team_ID IS NOT NULL
                ");
                $stmt->execute([$period_ID]);
                $targetTeamIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // 確定哪些團隊是當前用戶可以評分的
                $allowedTargetTeams = [];
                
                // 如果 pe_target_ID 是 JSON 格式且有 assign，檢查當前團隊是否在 assign 列表中
                if (count($assignTeams) > 0) {
                    if (in_array($my_team_ID, $assignTeams)) {
                        // 當前團隊是評分者，可以評分 receive 列表中的團隊
                        if (count($receiveTeams) > 0) {
                            $allowedTargetTeams = $receiveTeams;
                        } else if (count($targetTeamIds) > 0) {
                            // 如果沒有明確的 receive，使用 petargetdata 中的團隊（但需要進一步檢查）
                            $allowedTargetTeams = array_map('intval', $targetTeamIds);
                        }
                    }
                } else {
                    // 如果沒有 assign/receive 設定，檢查 petargetdata 表
                    // 這裡需要根據實際業務邏輯：可能需要檢查是否有其他表定義評分關係
                    // 暫時使用 petargetdata 中的所有被評分團隊
                    if (count($targetTeamIds) > 0) {
                        $allowedTargetTeams = array_map('intval', $targetTeamIds);
                    }
                }

                // 構建查詢條件
                $whereConditions = ['tm.team_ID != ?', 'tm.tm_status = 1'];
                $params = [$my_team_ID];

                // 如果指定了允許的被評分團隊
                if (count($allowedTargetTeams) > 0) {
                    $placeholders = implode(',', array_fill(0, count($allowedTargetTeams), '?'));
                    $whereConditions[] = "tm.team_ID IN ($placeholders)";
                    $params = array_merge($params, $allowedTargetTeams);
                } else {
                    // 如果沒有允許的被評分團隊，返回空結果
                    $whereConditions[] = "1 = 0"; // 永遠不匹配的條件
                }

                $stmt = $conn->prepare("
                    SELECT 
                        tm.{$teamUserField} AS u_ID,
                        COALESCE(ud.u_name, tm.{$teamUserField}) AS u_name,
                        ud.u_img,
                        t.team_ID,
                        t.team_project_name
                    FROM teammember tm
                    INNER JOIN teamdata t ON t.team_ID = tm.team_ID
                    INNER JOIN userrolesdata ur ON ur.{$userRoleUidField} = tm.{$teamUserField}
                        AND ur.role_ID = 6
                        AND ur.user_role_status = 1
                    LEFT JOIN userdata ud ON ud.u_ID = tm.{$teamUserField}
                    WHERE " . implode(' AND ', $whereConditions) . "
                    ORDER BY t.team_project_name, tm.{$teamUserField} ASC
                ");
                $stmt->execute($params);
                $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 按團隊分組
                $teamsMap = [];
                foreach ($allMembers as $member) {
                    $team_ID = $member['team_ID'];
                    if (!isset($teamsMap[$team_ID])) {
                        $teamsMap[$team_ID] = [
                            'team_ID' => $team_ID,
                            'team_project_name' => $member['team_project_name'],
                            'members' => []
                        ];
                    }
                    $teamsMap[$team_ID]['members'][] = $member;
                }
                $teams = array_values($teamsMap);
                $members = $allMembers;
            }

            // 獲取已評分記錄
            // 檢查使用哪個表：pereviewdata 或 peerreview
            $reviewTable = 'pereviewdata';
            try {
                $testStmt = $conn->query("SHOW TABLES LIKE 'pereviewdata'");
                if ($testStmt->rowCount() === 0) {
                    $reviewTable = 'peerreview';
                }
            } catch (Exception $e) {
                $reviewTable = 'peerreview';
            }
            
            $ratedRecords = [];
            
            if ($reviewTable === 'pereviewdata') {
                // 使用 pereviewdata 表結構
                // 檢查是否有 petarget_u_ID 欄位
                $hasPetargetUId = false;
                try {
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM {$reviewTable} LIKE 'petarget_u_ID'");
                    $checkStmt->execute();
                    $hasPetargetUId = $checkStmt->rowCount() > 0;
                } catch (Exception $e) {
                    $hasPetargetUId = false;
                }
                
                if ($hasPetargetUId) {
                    // 如果有 petarget_u_ID 欄位，使用它作為被評分者ID（這是用來記錄被評分者的）
                    $stmt = $conn->prepare("
                        SELECT 
                            pr.petarget_u_ID AS u_ID,
                            pr.score,
                            pr.peer_comment AS comment
                        FROM {$reviewTable} pr
                        WHERE pr.period_ID = ? AND pr.pe_u_ID = ?
                            AND pr.petarget_u_ID IS NOT NULL
                    ");
                } else {
                    // 如果沒有 petarget_u_ID 欄位，無法精確記錄被評分者
                    // 這種情況下，每個團隊的所有成員會共用同一個 pe_target_ID
                    // 但我們需要其他方式來識別具體被評分者
                    $stmt = $conn->prepare("
                        SELECT 
                            pr.pe_target_ID AS pe_target_ID,
                            pr.score,
                            pr.peer_comment AS comment
                        FROM {$reviewTable} pr
                        WHERE pr.period_ID = ? AND pr.pe_u_ID = ?
                    ");
                }
                
                $stmt->execute([$period_ID, $uid]);
                $reviewData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if ($hasPetargetUId) {
                    // 使用 petarget_u_ID 作為被評分者的 u_ID
                    foreach ($reviewData as $review) {
                        $targetUserId = strval($review['u_ID']);
                        if (!empty($targetUserId)) {
                            $ratedRecords[$targetUserId] = [
                                'score' => (int)$review['score'],
                                'comment' => $review['comment'] ?? ''
                            ];
                        }
                    }
                } else {
                    // 如果沒有 petarget_u_ID 欄位，需要通過 pe_target_ID 找到團隊，再找到成員
                    // 這種情況下無法精確識別被評分者，暫時跳過
                }
            } else {
                // 使用舊的 peerreview 表結構
                $stmt = $conn->prepare("
                    SELECT review_b_u_ID AS u_ID, score, review_comment AS comment
                    FROM {$reviewTable}
                    WHERE period_ID = ? AND review_a_u_ID = ?
                ");
                $stmt->execute([$period_ID, $uid]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $ratedRecords[$row['u_ID']] = [
                        'score' => (int)$row['score'],
                        'comment' => $row['comment'] ?? ''
                    ];
                }
            }

            // 如果沒有找到團隊或成員，返回提示
            if (empty($members)) {
                if (!$my_team_ID) {
                    echo json_encode([
                        'period' => $period,
                        'teams' => [],
                        'members' => [],
                        'ratedRecords' => [],
                        'message' => '您尚未加入任何團隊'
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode([
                        'period' => $period,
                        'teams' => [],
                        'members' => [],
                        'ratedRecords' => [],
                        'message' => '目前沒有需要評分的成員'
                    ], JSON_UNESCAPED_UNICODE);
                }
            } else {
            echo json_encode([
                'period' => $period,
                'teams' => $teams,
                'members' => $members,
                'ratedRecords' => $ratedRecords,
                'isPeriodNotStarted' => $isPeriodNotStarted,
                'isPeriodEnded' => $isPeriodEnded
            ], JSON_UNESCAPED_UNICODE);
            }

        } catch (PDOException $e) {
            http_response_code(200);
            echo json_encode(['error' => '取得團隊資料失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'submit_rating':
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            if (!$data || !isset($data['period_ID']) || !isset($data['ratings'])) {
                http_response_code(200);
                echo json_encode(['error' => '資料格式錯誤'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $period_ID = (int)$data['period_ID'];
            $ratings = $data['ratings'];
            $uid = strval($_SESSION['u_ID']);
            
            // 檢查時段是否已開始和已結束
            $tableName = 'perioddata';
            try {
                $testStmt = $conn->query("SHOW TABLES LIKE 'perioddata'");
                if ($testStmt->rowCount() === 0) {
                    $testStmt2 = $conn->query("SHOW TABLES LIKE 'ReviewPeriods'");
                    if ($testStmt2->rowCount() > 0) {
                        $tableName = 'ReviewPeriods';
                    } else {
                        $tableName = 'reviewperiods';
                    }
                }
            } catch (Exception $e) {
                $tableName = 'perioddata';
            }
            
            $stmt = $conn->prepare("
                SELECT period_start_d, period_end_d
                FROM {$tableName}
                WHERE period_ID = ?
            ");
            $stmt->execute([$period_ID]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($period) {
                $now = new DateTime();
                
                // 檢查時段是否已開始（使用完整的日期時間，包含時分秒）
                if (isset($period['period_start_d'])) {
                    $periodStartDate = new DateTime($period['period_start_d']);
                    // 使用實際的開始時間，不修改時分秒
                    
                    if ($now < $periodStartDate) {
                        http_response_code(200);
                        echo json_encode(['error' => '此評分時段尚未開始，無法進行評分'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                }
                
                // 檢查時段是否已結束（使用完整的日期時間，包含時分秒）
                if (isset($period['period_end_d'])) {
                    $periodEndDate = new DateTime($period['period_end_d']);
                    // 使用實際的結束時間，不修改時分秒
                    
                    if ($now > $periodEndDate) {
                        http_response_code(200);
                        echo json_encode(['error' => '此評分時段已結束，無法再進行評分'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                }
            }

            // 檢查使用哪個表：pereviewdata 或 peerreview
            $reviewTable = 'pereviewdata';
            try {
                $testStmt = $conn->query("SHOW TABLES LIKE 'pereviewdata'");
                if ($testStmt->rowCount() === 0) {
                    $reviewTable = 'peerreview';
                }
            } catch (Exception $e) {
                $reviewTable = 'peerreview';
            }
            
            // 檢查是否已評分
            if ($reviewTable === 'pereviewdata') {
                $sqlCheck = "SELECT COUNT(*) FROM {$reviewTable} WHERE period_ID = ? AND pe_u_ID = ?";
            } else {
                $sqlCheck = "SELECT COUNT(*) FROM {$reviewTable} WHERE period_ID = ? AND review_a_u_ID = ?";
            }
            $ratedCount = $conn->prepare($sqlCheck);
            $ratedCount->execute([$period_ID, $uid]);
            if ($ratedCount->fetchColumn() > 0) {
                http_response_code(200);
                echo json_encode(['error' => '您已經完成評分，無法再次提交'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $successCount = 0;
            
            if ($reviewTable === 'pereviewdata') {
                // 使用 pereviewdata 表結構
                // 檢查是否有 petarget_u_ID 欄位
                $hasPetargetUId = false;
                try {
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM {$reviewTable} LIKE 'petarget_u_ID'");
                    $checkStmt->execute();
                    $hasPetargetUId = $checkStmt->rowCount() > 0;
                } catch (Exception $e) {
                    $hasPetargetUId = false;
                }
                
                // 檢查 teammember 表的用戶欄位名稱
                $teamUserField = 'u_ID';
                try {
                    $checkStmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                    $checkStmt->execute();
                    if ($checkStmt->rowCount() > 0) {
                        $teamUserField = 'team_u_ID';
                    }
                } catch (Exception $e) {
                    // 使用默認值
                }
                
                foreach ($ratings as $review_b_u_ID => $entry) {
                    $score = isset($entry['score']) ? (int)$entry['score'] : 0;
                    $comment = isset($entry['comment']) ? trim($entry['comment']) : '';
                    
                    // 每個成員都要有評分記錄（score可以是0）
                    if ($score >= 0 && $score <= 5) {
                        // 找到被評分者所屬的團隊
                        $stmt = $conn->prepare("
                            SELECT team_ID 
                            FROM teammember 
                            WHERE {$teamUserField} = ? AND tm_status = 1
                            LIMIT 1
                        ");
                        $stmt->execute([$review_b_u_ID]);
                        $targetTeamId = $stmt->fetchColumn();
                        
                        if ($targetTeamId) {
                            // 找到對應的 petargetdata 記錄（根據 period_ID 和 team_ID）
                            // pe_target_ID 是 petargetdata 的 ID，不能直接存儲 u_ID（有外鍵約束）
                            $stmt = $conn->prepare("
                                SELECT pe_target_ID
                                FROM petargetdata
                                WHERE period_ID = ? AND pe_team_ID = ?
                                LIMIT 1
                            ");
                            $stmt->execute([$period_ID, $targetTeamId]);
                            $pe_target_ID = $stmt->fetchColumn();
                            
                            if (!$pe_target_ID) {
                                // 如果沒有對應的 petargetdata 記錄，無法插入（外鍵約束）
                                continue;
                            }
                            
                            if ($hasPetargetUId) {
                                // 使用 petarget_u_ID 欄位存儲被評分者的 u_ID（用戶要求：pe_target_ID 用來記錄被評分者）
                                // 但由於外鍵約束，pe_target_ID 必須是 petargetdata 的 ID
                                // 所以使用 petarget_u_ID 欄位來存儲被評分者的 u_ID
                                $sql = "
                                    INSERT INTO {$reviewTable}
                                      (period_ID, pe_target_ID, pe_u_ID, petarget_u_ID, score, peer_comment, created_d)
                                    VALUES
                                      (:pid, :target, :uid, :target_uid, :s, :c, NOW())
                                    ON DUPLICATE KEY UPDATE
                                      score = VALUES(score),
                                      peer_comment = VALUES(peer_comment),
                                      petarget_u_ID = VALUES(petarget_u_ID),
                                      created_d = NOW()
                                ";
                                $stmt = $conn->prepare($sql);
                                $stmt->execute([
                                    ':pid' => $period_ID,
                                    ':target' => $pe_target_ID, // petargetdata 的 pe_target_ID（必須符合外鍵約束）
                                    ':uid' => $uid,
                                    ':target_uid' => strval($review_b_u_ID), // 被評分者的 u_ID 存在 petarget_u_ID
                                    ':s' => $score,
                                    ':c' => $comment
                                ]);
                            } else {
                                // 如果沒有 petarget_u_ID 欄位，無法存儲被評分者的 u_ID
                                // 只能使用 pe_target_ID（但它是 petargetdata 的 ID，不是 u_ID）
                                // 這種情況下，每個團隊的所有成員會共用同一個 pe_target_ID
                                $sql = "
                                    INSERT INTO {$reviewTable}
                                      (period_ID, pe_target_ID, pe_u_ID, score, peer_comment, created_d)
                                    VALUES
                                      (:pid, :target, :uid, :s, :c, NOW())
                                    ON DUPLICATE KEY UPDATE
                                      score = VALUES(score),
                                      peer_comment = VALUES(peer_comment),
                                      created_d = NOW()
                                ";
                                $stmt = $conn->prepare($sql);
                                $stmt->execute([
                                    ':pid' => $period_ID,
                                    ':target' => $pe_target_ID, // petargetdata 的 pe_target_ID
                                    ':uid' => $uid,
                                    ':s' => $score,
                                    ':c' => $comment
                                ]);
                            }
                            $successCount++;
                        }
                    }
                }
            } else {
                // 使用舊的 peerreview 表結構
                $sql = "
                    INSERT INTO {$reviewTable}
                      (period_ID, review_a_u_ID, review_b_u_ID, score, review_comment, review_created_d)
                    VALUES
                      (:pid, :a, :b, :s, :c, NOW())
                    ON DUPLICATE KEY UPDATE
                      score = VALUES(score),
                      review_comment = VALUES(review_comment),
                      review_created_d = NOW()
                ";
                $stmt = $conn->prepare($sql);

                foreach ($ratings as $review_b_u_ID => $entry) {
                    $score = isset($entry['score']) ? (int)$entry['score'] : 0;
                    $comment = isset($entry['comment']) ? trim($entry['comment']) : '';
                    // 每個成員都要有評分記錄（score可以是0）
                    if ($score >= 0 && $score <= 5) {
                        $stmt->execute([
                            ':pid' => $period_ID,
                            ':a' => $uid,
                            ':b' => strval($review_b_u_ID),
                            ':s' => $score,
                            ':c' => $comment
                        ]);
                        $successCount++;
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => "成功提交 {$successCount} 筆評分"], JSON_UNESCAPED_UNICODE);

        } catch (PDOException $e) {
            http_response_code(200);
            echo json_encode(['error' => '資料寫入失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        break;

    default:
        echo json_encode(['error' => '未知的操作'], JSON_UNESCAPED_UNICODE);
        break;
}

