<?php
session_start();
require '../includes/pdo.php';

$sort = $_REQUEST['sort'] ?? 'created';

function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function jsonResponse(array $payload, int $statusCode = 200) {
    if (headers_sent() === false) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function parseTeamTarget($raw) {
    $result = [
        'assign' => [],
        'receive' => [],
        'is_all' => false
    ];
    if (!$raw || strtoupper(trim($raw)) === 'ALL') {
        $result['is_all'] = true;
        return $result;
    }
    $trimmed = trim((string)$raw);
    if ($trimmed !== '' && $trimmed[0] === '{') {
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $result['assign'] = array_values(array_filter(array_map('strval', $decoded['assign'] ?? [])));
            $result['receive'] = array_values(array_filter(array_map('strval', $decoded['receive'] ?? [])));
            return $result;
        }
    }
    $assignList = array_filter(array_map('trim', explode(',', $raw)), function($v){ return $v !== ''; });
    $result['assign'] = array_values(array_map('strval', $assignList));
    return $result;
}

function normalizeIdList($raw) {
    if (!$raw) return [];
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = preg_split('/[,\s]+/', (string)$raw);
    }
    $ids = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item === '') continue;
        if (!is_numeric($item)) continue;
        $ids[] = (int)$item;
    }
    return array_values(array_unique($ids));
}

function getPostedCohortIdList() {
    $list = normalizeIdList($_POST['cohort_values'] ?? null);
    if (!$list) {
        $fallback = resolvePostedCohortId();
        if ($fallback) $list = [(int)$fallback];
    }
    return $list;
}

function getPostedClassIdList() {
    $raw = $_POST['pe_class_ID'] ?? null;
    $list = normalizeIdList($raw);
    return $list;
}

function tableExists(PDO $conn, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];
    try {
        $quoted = $conn->quote($tableName);
        $stmt = $conn->query("SHOW TABLES LIKE {$quoted}");
        $cache[$tableName] = $stmt && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        $cache[$tableName] = false;
    }
    return $cache[$tableName];
}

function columnExists(PDO $conn, $tableName, $columnName) {
    static $cache = [];
    $key = "{$tableName}.{$columnName}";
    if (isset($cache[$key])) return $cache[$key];
    try {
        $quotedTable = $conn->quote($tableName);
        $quotedColumn = $conn->quote($columnName);
        $stmt = $conn->query("SHOW COLUMNS FROM {$quotedTable} LIKE {$quotedColumn}");
        $cache[$key] = $stmt && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function teamTableColumns(PDO $conn) {
    static $columns = null;
    if ($columns !== null) return $columns;
    $columns = [];
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM teamdata");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['Field']] = true;
            }
        }
    } catch (Exception $e) {
        $columns = [];
    }
    return $columns;
}

function teamColumnExists(PDO $conn, $column) {
    $columns = teamTableColumns($conn);
    return isset($columns[$column]);
}

function periodTableColumns(PDO $conn, bool $refresh = false) {
    static $columns = null;
    if (!$refresh && $columns !== null) return $columns;
    $columns = [];
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM perioddata");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['Field']] = true;
            }
        }
    } catch (Exception $e) {
        $columns = [];
    }
    return $columns;
}

function periodColumnExists(PDO $conn, $column, bool $refresh = false) {
    $columns = periodTableColumns($conn, $refresh);
    return isset($columns[$column]);
}

function getPeriodStatusColumn(PDO $conn) {
    static $statusColumn = null;
    if ($statusColumn !== null) return $statusColumn;
    if (periodColumnExists($conn, 'pe_status')) {
        $statusColumn = 'pe_status';
    } elseif (periodColumnExists($conn, 'status_ID')) {
        $statusColumn = 'status_ID';
    } else {
        $statusColumn = null;
    }
    return $statusColumn;
}

function getPeriodModeColumn(PDO $conn) {
    static $modeColumn = null;
    if ($modeColumn !== null) return $modeColumn;
    if (periodColumnExists($conn, 'period_type')) {
        $modeColumn = 'period_type';
    } elseif (periodColumnExists($conn, 'pe_mode')) {
        $modeColumn = 'pe_mode';
    } else {
        $modeColumn = null;
    }
    return $modeColumn;
}

$attemptedAddTargetColumn = false;
function ensurePeriodTargetStorage(PDO $conn) {
    global $attemptedAddTargetColumn;
    if (periodColumnExists($conn, 'pe_target_ID')) {
        return true;
    }
    if ($attemptedAddTargetColumn) {
        return false;
    }
    $attemptedAddTargetColumn = true;
    try {
        $conn->exec("ALTER TABLE perioddata ADD COLUMN pe_target_ID TEXT NULL");
    } catch (Exception $e) {
        // ignore
    }
    return periodColumnExists($conn, 'pe_target_ID', true);
}

$attemptedAddRoleColumn = false;
function ensurePeriodRoleStorage(PDO $conn) {
    global $attemptedAddRoleColumn;
    if (periodColumnExists($conn, 'pe_role_ID')) {
        return true;
    }
    if ($attemptedAddRoleColumn) {
        return false;
    }
    $attemptedAddRoleColumn = true;
    try {
        $conn->exec("ALTER TABLE perioddata ADD COLUMN pe_role_ID INT NULL COMMENT '創建時的角色ID'");
    } catch (Exception $e) {
        // ignore
    }
    return periodColumnExists($conn, 'pe_role_ID', true);
}

function petargetTableColumns(PDO $conn) {
    static $columns = null;
    if ($columns !== null) return $columns;
    $columns = [];
    if (!tableExists($conn, 'petargetdata')) {
        return $columns;
    }
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM petargetdata");
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[$col['Field']] = true;
            }
        }
    } catch (Exception $e) {
        $columns = [];
    }
    return $columns;
}

function petargetColumnExists(PDO $conn, $column) {
    $columns = petargetTableColumns($conn);
    return isset($columns[$column]);
}

function fetchTeamInfoByIds(PDO $conn, array $teamIds) {
    if (!$teamIds) return [];
    $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
    if (!$teamIds) return [];
    $columns = ['team_ID'];
    if (teamColumnExists($conn, 'class_ID')) $columns[] = 'class_ID';
    if (teamColumnExists($conn, 'cohort_ID')) $columns[] = 'cohort_ID';
    if (teamColumnExists($conn, 'grade_no')) $columns[] = 'grade_no';
    if (teamColumnExists($conn, 'team_project_name')) $columns[] = 'team_project_name';
    if (teamColumnExists($conn, 'team_status')) $columns[] = 'team_status';
    $columnSql = implode(', ', array_unique($columns));
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $sql = "SELECT {$columnSql} FROM teamdata WHERE team_ID IN ({$placeholders})";
    // 不再限制 team_status，確保停用團隊也能被載入
    $stmt = $conn->prepare($sql);
    $stmt->execute($teamIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string)$row['team_ID']] = $row;
    }
    return $map;
}

function fetchTeamsByFilters(PDO $conn, array $cohortIds, array $classIds) {
    $columns = ['team_ID'];
    if (teamColumnExists($conn, 'class_ID')) $columns[] = 'class_ID';
    if (teamColumnExists($conn, 'cohort_ID')) $columns[] = 'cohort_ID';
    if (teamColumnExists($conn, 'grade_no')) $columns[] = 'grade_no';
    if (teamColumnExists($conn, 'team_project_name')) $columns[] = 'team_project_name';
    if (teamColumnExists($conn, 'team_status')) $columns[] = 'team_status';
    $columnSql = implode(', ', array_unique($columns));
    $sql = "SELECT {$columnSql} FROM teamdata";
    $conditions = [];
    $params = [];
    // 不限制 team_status，讓被停用的團隊仍可作為被評分對象
    if ($cohortIds && teamColumnExists($conn, 'cohort_ID')) {
        $placeholders = implode(',', array_fill(0, count($cohortIds), '?'));
        $conditions[] = "cohort_ID IN ({$placeholders})";
        $params = array_merge($params, $cohortIds);
    }
    if ($classIds && teamColumnExists($conn, 'class_ID')) {
        $placeholders = implode(',', array_fill(0, count($classIds), '?'));
        $conditions[] = "class_ID IN ({$placeholders})";
        $params = array_merge($params, $classIds);
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string)$row['team_ID']] = $row;
    }
    return $map;
}

function fetchPeriodTargetTeams(PDO $conn, array $periodIds) {
    if (!$periodIds || !tableExists($conn, 'petargetdata')) {
        return [];
    }
    $periodIds = array_values(array_unique(array_filter(array_map('intval', $periodIds))));
    if (!$periodIds) return [];
    $placeholders = implode(',', array_fill(0, count($periodIds), '?'));
    // 檢查是否有 status_ID 欄位
    $hasStatus = petargetColumnExists($conn, 'status_ID');
    $statusColumn = $hasStatus ? ', status_ID' : '';
    $stmt = $conn->prepare("SELECT period_ID, pe_team_ID, pe_cohort_ID{$statusColumn} FROM petargetdata WHERE period_ID IN ($placeholders)");
    $stmt->execute($periodIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['period_ID'];
        if (!isset($map[$pid])) {
            $map[$pid] = [
                'team_ids' => [],          // 用於向後相容，包含所有團隊（去重後）
                'assign_team_ids' => [],   // status_ID = 1 的團隊（評分者）
                'receive_team_ids' => [],  // status_ID = 0 的團隊（被評分者）
                'cohort_ids' => []
            ];
        }
        $status = $hasStatus && isset($row['status_ID']) ? (int)$row['status_ID'] : null;
        $teamId = isset($row['pe_team_ID']) && $row['pe_team_ID'] !== null ? (int)$row['pe_team_ID'] : null;
        $cohortId = isset($row['pe_cohort_ID']) && $row['pe_cohort_ID'] !== null ? (int)$row['pe_cohort_ID'] : null;
        
        if ($teamId !== null) {
            // 根據 status_ID 分類
            if ($status === 1) {
                // 評分者（團隊間互評模式）
                if (!in_array($teamId, $map[$pid]['assign_team_ids'], true)) {
                    $map[$pid]['assign_team_ids'][] = $teamId;
                }
            } elseif ($status === 0 || $status === null) {
                // 被評分者（團隊內互評模式：所有團隊都是 status_ID=0；團隊間互評模式：被評分團隊）
                if (!in_array($teamId, $map[$pid]['receive_team_ids'], true)) {
                    $map[$pid]['receive_team_ids'][] = $teamId;
                }
            }
            
            // 同時加入到 team_ids（用於向後相容，包含所有團隊，去重）
            if (!in_array($teamId, $map[$pid]['team_ids'], true)) {
                $map[$pid]['team_ids'][] = $teamId;
            }
        }
        
        if ($cohortId !== null) {
            // 避免重複加入相同的屆別ID
            if (!in_array($cohortId, $map[$pid]['cohort_ids'], true)) {
                $map[$pid]['cohort_ids'][] = $cohortId;
            }
        }
    }
    return $map;
}

function syncPeriodTargets(PDO $conn, $periodId, $rawTargetValue, array $cohortIds, array $classIds, string $mode = 'in', $userId = null, $roleId = null, bool $protectExisting = false) {
    if (!$periodId || !tableExists($conn, 'petargetdata')) {
        return;
    }
    
    // 如果是指導老師（role_ID = 4），獲取他帶的團隊ID列表
    $teacherTeamIds = [];
    if ($roleId == 4 && $userId) {
        $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
        $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';
        
        $stmt = $conn->prepare("
            SELECT DISTINCT tm.team_ID
            FROM teammember tm
            JOIN userrolesdata ur ON ur.ur_u_ID = tm.{$teamUserField}
            JOIN teamdata t ON t.team_ID = tm.team_ID
            WHERE tm.{$teamUserField} = ?
              AND ur.role_ID = 4
              AND ur.user_role_status = 1
              AND t.team_status = 1
        ");
        $stmt->execute([$userId]);
        $teacherTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
    }
    
    // 如果是班導師（role_ID = 3），獲取他班級的團隊ID列表
    $classTeacherTeamIds = [];
    if ($roleId == 3 && $userId) {
        // 先獲取班導師所在的班級ID列表
        $stmt = $conn->prepare("
            SELECT DISTINCT class_ID
            FROM enrollmentdata
            WHERE enroll_u_ID = ? AND enroll_status = 1
        ");
        $stmt->execute([$userId]);
        $classTeacherClassIds = array_values(array_filter(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_ID')));
        
        if (!empty($classTeacherClassIds)) {
            $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
            $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';
            
            // 獲取屬於這些班級的團隊ID列表（通過團隊成員的班級來判斷）
            $phClass = implode(',', array_fill(0, count($classTeacherClassIds), '?'));
            $stmt = $conn->prepare("
                SELECT DISTINCT t.team_ID
                FROM teamdata t
                JOIN teammember tm ON tm.team_ID = t.team_ID
                JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
                WHERE t.team_status = 1
                  AND e.class_ID IN ($phClass)
                  AND e.enroll_status = 1
            ");
            $stmt->execute($classTeacherClassIds);
            $classTeacherTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
        }
    }
    
    // 合併指導老師和班導師的團隊限制
    $allowedTeamIds = [];
    if (!empty($teacherTeamIds)) {
        $allowedTeamIds = $teacherTeamIds;
    }
    if (!empty($classTeacherTeamIds)) {
        if (empty($allowedTeamIds)) {
            $allowedTeamIds = $classTeacherTeamIds;
        } else {
            // 如果同時是指導老師和班導師，取交集
            $allowedTeamIds = array_intersect($allowedTeamIds, $classTeacherTeamIds);
        }
    }
    
    $payload = parseTeamTarget($rawTargetValue);
    $assignMap = [];
    if (!empty($payload['assign'])) {
        $assignMap = fetchTeamInfoByIds($conn, $payload['assign']);
        // 如果有團隊限制（指導老師或班導師），過濾掉不符合的團隊
        if (!empty($allowedTeamIds)) {
            $assignMap = array_filter($assignMap, function($team) use ($allowedTeamIds) {
                return in_array($team['team_ID'], $allowedTeamIds);
            });
        }
    }
    if (!$assignMap && ($payload['is_all'] || $mode === 'in')) {
        $assignMap = fetchTeamsByFilters($conn, $cohortIds, $classIds);
        // 如果有團隊限制（指導老師或班導師），過濾掉不符合的團隊
        if (!empty($allowedTeamIds)) {
            $assignMap = array_filter($assignMap, function($team) use ($allowedTeamIds) {
                return in_array($team['team_ID'], $allowedTeamIds);
            });
        }
    }
    if ($mode === 'cross' && !$assignMap) {
        $assignMap = fetchTeamsByFilters($conn, $cohortIds, $classIds);
        // 如果有團隊限制（指導老師或班導師），過濾掉不符合的團隊
        if (!empty($allowedTeamIds)) {
            $assignMap = array_filter($assignMap, function($team) use ($allowedTeamIds) {
                return in_array($team['team_ID'], $allowedTeamIds);
            });
        }
    }
    $assignList = array_values($assignMap);

    $receiveMap = [];
    if ($mode === 'cross') {
        // 團隊間互評模式
        if (!empty($payload['receive'])) {
            // 如果有明確指定被評分團隊，使用指定的團隊
            $receiveMap = fetchTeamInfoByIds($conn, $payload['receive']);
            // 如果有團隊限制（指導老師或班導師），過濾掉不符合的團隊
            if (!empty($allowedTeamIds)) {
                $receiveMap = array_filter($receiveMap, function($team) use ($allowedTeamIds) {
                    return in_array($team['team_ID'], $allowedTeamIds);
                });
            }
        }
        if (!$receiveMap) {
            // 如果沒有明確指定被評分團隊，預設為所有團隊（包括評分者自己）
            // 注意：評分者也可以同時是被評者，所以不應該從 receiveMap 中移除 assignList
            $receiveMap = fetchTeamsByFilters($conn, $cohortIds, $classIds);
            // 如果有團隊限制（指導老師或班導師），過濾掉不符合的團隊
            if (!empty($allowedTeamIds)) {
                $receiveMap = array_filter($receiveMap, function($team) use ($allowedTeamIds) {
                    return in_array($team['team_ID'], $allowedTeamIds);
                });
            }
        }
        // 不再移除 assignList 中的團隊，因為一個團隊可以同時是評分者和被評者
        // 例如：只指定 A 為評分者時，A 會同時有 status_ID=1 和 status_ID=0 的記錄
    } else {
        // 團隊內互評模式：所有選入的團隊都是被評分團隊（status_ID = 0）
        if ($assignList) {
            foreach ($assignList as $info) {
                $key = (string)($info['team_ID'] ?? '');
                if ($key !== '') {
                    $receiveMap[$key] = $info;
                }
            }
        } else {
            $receiveMap = fetchTeamsByFilters($conn, $cohortIds, $classIds);
            // 如果有團隊限制（指導老師或班導師），過濾掉不符合的團隊
            if (!empty($allowedTeamIds)) {
                $receiveMap = array_filter($receiveMap, function($team) use ($allowedTeamIds) {
                    return in_array($team['team_ID'], $allowedTeamIds);
                });
            }
        }
    }
    $receiveList = array_values($receiveMap);

    // 如果需要保護既有資料，確認該時段是否已有互評紀錄
    if ($protectExisting && tableExists($conn, 'pereviewdata')) {
        $stmtCheck = $conn->prepare("
            SELECT COUNT(*) 
            FROM pereviewdata pr 
            JOIN petargetdata pt ON pr.pe_target_ID = pt.pe_target_ID 
            WHERE pt.period_ID = ?
        ");
        $stmtCheck->execute([$periodId]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            throw new Exception('該評分時段已有互評紀錄，請先刪除相關評分後再變更指定團隊');
        }
    }

    // 刪除該評分時段的所有舊記錄
    $conn->prepare("DELETE FROM petargetdata WHERE period_ID=?")->execute([$periodId]);
    if (!$assignList && !$receiveList) {
        return;
    }
    $columns = ['period_ID', 'pe_team_ID'];
    $valuesTemplate = '(?, ?';
    $petargetHasClass = petargetColumnExists($conn, 'pe_class_ID');
    $petargetHasCohort = petargetColumnExists($conn, 'pe_cohort_ID');
    $petargetHasGrade = petargetColumnExists($conn, 'pe_grade_no');
    $petargetHasStatus = petargetColumnExists($conn, 'status_ID');
    $includeClass = $petargetHasClass && teamColumnExists($conn, 'class_ID');
    $includeCohort = $petargetHasCohort && teamColumnExists($conn, 'cohort_ID');
    $includeGrade = $petargetHasGrade && teamColumnExists($conn, 'grade_no');
    if ($includeClass) { $columns[] = 'pe_class_ID'; $valuesTemplate .= ', ?'; }
    if ($includeCohort) { $columns[] = 'pe_cohort_ID'; $valuesTemplate .= ', ?'; }
    if ($includeGrade) { $columns[] = 'pe_grade_no'; $valuesTemplate .= ', ?'; }
    if ($petargetHasStatus) { $columns[] = 'status_ID'; $valuesTemplate .= ', ?'; }
    $valuesTemplate .= ')';
    $columnSql = implode(', ', $columns);
    $sql = "INSERT INTO petargetdata ({$columnSql}) VALUES {$valuesTemplate}";
    $stmt = $conn->prepare($sql);
    $insertRows = [];
    
    if ($mode === 'in') {
        // 團隊內互評：所有選入的團隊都是 status_ID = 0（被評分團隊），不區分評分者與被評者
        // 所以只插入 receiveList，且 status_ID = 0
        foreach ($receiveList as $info) {
            $insertRows[] = [$info, 0];
        }
    } else {
        // 團隊間互評：指定團隊為評分者（status_ID = 1），被評分團隊為 status_ID = 0
        // 一個團隊可以同時是評分者和被評者，所以會產生兩筆記錄（status_ID=1 和 status_ID=0）
        // 例如：只指定 A 為評分者時，會產生 A=1, A=0, B=0, C=0
        
        // 插入評分者（status_ID = 1）
        foreach ($assignList as $info) {
            $teamId = (int)($info['team_ID'] ?? 0);
            if ($teamId > 0) {
                $insertRows[] = [$info, 1];
            }
        }
        
        // 插入被評分團隊（status_ID = 0），包括評分者自己（如果評分者也在被評列表中）
        foreach ($receiveList as $info) {
            $teamId = (int)($info['team_ID'] ?? 0);
            if ($teamId > 0) {
                $insertRows[] = [$info, 0];
            }
        }
    }
    
    // 獲取用戶選擇的屆別ID（優先使用第一個選中的屆別）
    $selectedCohortId = null;
    if ($cohortIds && count($cohortIds) > 0) {
        $selectedCohortId = (int)$cohortIds[0];
    }
    
    foreach ($insertRows as [$info, $statusValue]) {
        $params = [$periodId, $info['team_ID']];
        // 班級ID從團隊資料中獲取（每個團隊都屬於一個特定的班級）
        // 如果團隊資料中沒有 class_ID，且用戶選擇了班級，則使用第一個選中的班級ID
        if ($includeClass) {
            $classId = $info['class_ID'] ?? null;
            // 如果團隊資料中沒有 class_ID，且用戶選擇了班級，使用第一個選中的班級ID
            if ($classId === null && $classIds && count($classIds) > 0) {
                $classId = (int)$classIds[0];
            }
            $params[] = $classId;
        }
        // 屆別ID從團隊資料中獲取，如果團隊資料中沒有則使用用戶選擇的屆別ID
        if ($includeCohort) {
            $cohortId = $info['cohort_ID'] ?? null;
            // 如果團隊資料中沒有 cohort_ID，且用戶選擇了屆別，使用第一個選中的屆別ID
            if ($cohortId === null && $selectedCohortId !== null) {
                $cohortId = $selectedCohortId;
            }
            $params[] = $cohortId;
        }
        // 年級從團隊資料中獲取（因為前端沒有年級選擇器）
        if ($includeGrade) $params[] = $info['grade_no'] ?? null;
        if ($petargetHasStatus) $params[] = $statusValue;
        $stmt->execute($params);
    }
    
    // 在團隊內互評模式下，確保刪除所有 status_ID = 1 的記錄（以防萬一有錯誤的資料）
    if ($mode === 'in' && $petargetHasStatus) {
        $conn->prepare("DELETE FROM petargetdata WHERE period_ID=? AND status_ID=1")->execute([$periodId]);
    }
}

function describeTeamSelection(array $payload, string $role, array $teamNameMap) {
    $isAll = !empty($payload['is_all']);
    if ($role === 'assign') {
        if ($isAll) return '全部 (ALL)';
    } else {
        if ($isAll) return 'ALL';
        $mode = $payload['mode'] ?? null;
        if ((!isset($payload[$role]) || !count($payload[$role])) && $mode === 'cross') {
            return 'ALL';
        }
    }
    $ids = $payload[$role] ?? [];
    if (!$ids || !count($ids)) {
        return $role === 'receive' ? '－' : '－';
    }
    $labels = [];
    foreach ($ids as $id) {
        $key = (string)$id;
        if (isset($teamNameMap[$key])) {
            $labels[] = $teamNameMap[$key];
        }
    }
    if (count($labels) === 1) {
        return $labels[0];
    }
    if (!count($labels)) {
        return count($ids) > 1 ? '多個團隊' : $ids[0];
    }
    return '多個團隊 (' . count($labels) . ')';
}

function resolvePostedCohortId() {
    $primary = $_POST['cohort_primary'] ?? null;
    $raw = $_POST['cohort_ID'] ?? null;
    if (is_array($raw)) {
        $raw = $raw[0] ?? null;
    }
    return $primary ?: $raw;
}

function resolvePostedClassId() {
    $raw = $_POST['pe_class_ID'] ?? null;
    if (is_array($raw)) {
        $raw = $raw[0] ?? null;
    }
    return ($raw === '' ? null : $raw);
}

function resolvePostedMode() {
    $raw = strtolower(trim($_POST['pe_mode'] ?? $_POST['mode_value'] ?? ''));
    return $raw === 'cross' ? 'cross' : 'in';
}

$periodStatusColumn = getPeriodStatusColumn($conn);
$periodModeColumn = getPeriodModeColumn($conn);

/* 排序 */
switch ($sort) {
    case 'start':  $orderBy = 'ORDER BY p.period_start_d DESC, p.period_ID DESC'; break;
    case 'end':    $orderBy = 'ORDER BY p.period_end_d DESC, p.period_ID DESC'; break;
    case 'active':
        if ($periodStatusColumn) {
            $orderBy = "ORDER BY p.{$periodStatusColumn} DESC, p.pe_created_d DESC";
        } else {
            $orderBy = 'ORDER BY p.pe_created_d DESC, p.period_ID DESC';
        }
        break;
    default:       $orderBy = 'ORDER BY p.pe_created_d DESC, p.period_ID DESC';
}

/* CRUD: create */
if (($_POST['action'] ?? '') === 'create') {
    try {
    // 驗證開始時間和結束時間
    $startTime = $_POST['period_start_d'] ?? '';
    $endTime = $_POST['period_end_d'] ?? '';
    if ($startTime && $endTime) {
        $startTimestamp = strtotime($startTime);
        $endTimestamp = strtotime($endTime);
        if ($startTimestamp === false || $endTimestamp === false) {
            if (isAjaxRequest()) {
                jsonResponse([
                    'success' => false,
                    'msg' => '開始時間或結束時間格式錯誤'
                ], 200);
            }
            throw new Exception('開始時間或結束時間格式錯誤');
        }
        if ($endTimestamp <= $startTimestamp) {
            if (isAjaxRequest()) {
                jsonResponse([
                    'success' => false,
                    'msg' => '結束時間必須晚於開始時間'
                ], 200);
            }
            throw new Exception('結束時間必須晚於開始時間');
        }
    }
    // 檢查欄位是否存在
    $hasCohortId = false;
    $hasPeTargetId = false;
    $hasPeRoleId = false;
    $hasPeClassId = false;
    try {
        $checkStmt = $conn->query("SHOW COLUMNS FROM perioddata");
        $columns = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasCohortId = in_array('cohort_ID', $columns);
        $hasPeTargetId = in_array('pe_target_ID', $columns);
        $hasPeRoleId = in_array('pe_role_ID', $columns);
        $hasPeClassId = in_array('pe_class_ID', $columns);
    } catch (Exception $e) {
        // 如果檢查失敗，使用預設值
    }

    if (!$hasPeTargetId && ensurePeriodTargetStorage($conn)) {
        $hasPeTargetId = true;
    }
    
    // 確保 pe_role_ID 欄位存在
    if (!$hasPeRoleId && ensurePeriodRoleStorage($conn)) {
        $hasPeRoleId = true;
    }

    // 根據欄位存在情況動態建立 SQL
    $fields = ['period_start_d', 'period_end_d', 'period_title'];
    $values = [$_POST['period_start_d'], $_POST['period_end_d'], $_POST['period_title']];
    $placeholders = ['?', '?', '?'];

    if ($hasPeTargetId) {
        $rawTarget = $_POST['pe_target_ID'] ?? null;
        if ($rawTarget !== null) {
            $fields[] = 'pe_target_ID';
            $values[] = $rawTarget;
            $placeholders[] = '?';
        }
    }
    if ($hasCohortId) {
        $fields[] = 'cohort_ID';
        // 處理 cohort_values：可能是字串或陣列
        $cohortValue = $_POST['cohort_values'] ?? null;
        if (is_array($cohortValue)) {
            $cohortValue = $cohortValue[0] ?? null;
        }
        // 如果是字串，可能是逗號分隔的多個值，取第一個
        if (is_string($cohortValue) && $cohortValue !== '') {
            $cohortParts = explode(',', $cohortValue);
            $cohortValue = trim($cohortParts[0]);
        }
        $values[] = $cohortValue ? (int)$cohortValue : null;
        $placeholders[] = '?';
    }
    if ($hasPeClassId) {
        $fields[] = 'pe_class_ID';
        $values[] = ($classId = resolvePostedClassId()) !== null ? (int)$classId : null;
        $placeholders[] = '?';
    }

    $fields[] = 'pe_created_d';
    $placeholders[] = 'NOW()';

    $fields[] = 'pe_created_u_ID';
    $values[] = $_SESSION['u_ID'] ?? null;
    $placeholders[] = '?';

    if ($hasPeRoleId) {
        $fields[] = 'pe_role_ID';
        $values[] = $_SESSION['role_ID'] ?? null;
        $placeholders[] = '?';
    }

    $modeValue = resolvePostedMode();
    if ($periodModeColumn) {
        $fields[] = $periodModeColumn;
        $values[] = $modeValue;
        $placeholders[] = '?';
    }

    if ($periodStatusColumn) {
        $fields[] = $periodStatusColumn;
        $values[] = isset($_POST['pe_status']) ? 1 : 0;
        $placeholders[] = '?';
    }

    $sql = "INSERT INTO perioddata (" . implode(', ', $fields) . ")
            VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $conn->prepare($sql);
        $stmt->execute($values);
        $newPeriodId = (int)$conn->lastInsertId();
        $cohortList = getPostedCohortIdList();
        $classList = getPostedClassIdList();
        $userId = $_SESSION['u_ID'] ?? null;
        $roleId = $_SESSION['role_ID'] ?? null;
        syncPeriodTargets($conn, $newPeriodId, $_POST['pe_target_ID'] ?? '', $cohortList, $classList, $modeValue, $userId, $roleId);
        
        // 如果是 AJAX 請求，返回 JSON；否則重定向
        if (isAjaxRequest()) {
            jsonResponse(['success' => true, 'msg' => '已新增評分時段']);
        }
        header("Location: checkreviewperiods.php?sort=$sort");
        exit;
    } catch (Exception $e) {
        if (isAjaxRequest()) {
            jsonResponse([
                'success' => false,
                'msg' => '新增評分時段失敗：' . $e->getMessage()
            ], 200);
        }
        throw $e;
    }
}

/* CRUD: update */
if (($_POST['action'] ?? '') === 'update') {
    try {
    // 驗證開始時間和結束時間
    $startTime = $_POST['period_start_d'] ?? '';
    $endTime = $_POST['period_end_d'] ?? '';
    if ($startTime && $endTime) {
        $startTimestamp = strtotime($startTime);
        $endTimestamp = strtotime($endTime);
        if ($startTimestamp === false || $endTimestamp === false) {
            if (isAjaxRequest()) {
                jsonResponse([
                    'success' => false,
                    'msg' => '開始時間或結束時間格式錯誤'
                ], 200);
            }
            throw new Exception('開始時間或結束時間格式錯誤');
        }
        if ($endTimestamp <= $startTimestamp) {
            if (isAjaxRequest()) {
                jsonResponse([
                    'success' => false,
                    'msg' => '結束時間必須晚於開始時間'
                ], 200);
            }
            throw new Exception('結束時間必須晚於開始時間');
        }
    }
    // 檢查欄位是否存在
    $hasCohortId = false;
    $hasPeTargetId = false;
    $hasPeClassId = false;
    try {
        $checkStmt = $conn->query("SHOW COLUMNS FROM perioddata");
        $columns = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasCohortId = in_array('cohort_ID', $columns);
        $hasPeTargetId = in_array('pe_target_ID', $columns);
        $hasPeClassId = in_array('pe_class_ID', $columns);
    } catch (Exception $e) {
        // 如果檢查失敗，使用預設值
    }

    if (!$hasPeTargetId && ensurePeriodTargetStorage($conn)) {
        $hasPeTargetId = true;
    }

    // 根據欄位存在情況動態建立 SQL
    $sets = [
        'period_start_d=?',
        'period_end_d=?',
        'period_title=?'
    ];
    $values = [
        $_POST['period_start_d'],
        $_POST['period_end_d'],
        $_POST['period_title']
    ];

    if ($hasPeTargetId) {
        $rawTarget = $_POST['pe_target_ID'] ?? null;
        if ($rawTarget !== null) {
            $sets[] = 'pe_target_ID=?';
            $values[] = $rawTarget;
        }
    }
    if ($hasCohortId) {
        $sets[] = 'cohort_ID=?';
        // 處理 cohort_values：可能是字串或陣列
        $cohortValue = $_POST['cohort_values'] ?? null;
        if (is_array($cohortValue)) {
            $cohortValue = $cohortValue[0] ?? null;
        }
        // 如果是字串，可能是逗號分隔的多個值，取第一個
        if (is_string($cohortValue) && $cohortValue !== '') {
            $cohortParts = explode(',', $cohortValue);
            $cohortValue = trim($cohortParts[0]);
        }
        $values[] = $cohortValue ? (int)$cohortValue : null;
    }
    if ($hasPeClassId) {
        $sets[] = 'pe_class_ID=?';
        $values[] = ($classId = resolvePostedClassId()) !== null ? (int)$classId : null;
    }

    $modeValue = resolvePostedMode();
    if ($periodModeColumn) {
        $sets[] = "{$periodModeColumn}=?";
        $values[] = $modeValue;
    }

    if ($periodStatusColumn) {
        $sets[] = "{$periodStatusColumn}=?";
        $values[] = isset($_POST['pe_status']) ? 1 : 0;
    }
    $values[] = $_POST['period_ID']; // WHERE 條件

    $sql = "UPDATE perioddata SET " . implode(', ', $sets) . " WHERE period_ID=?";

        $stmt = $conn->prepare($sql);
        $stmt->execute($values);
        $cohortList = getPostedCohortIdList();
        $classList = getPostedClassIdList();
        $userId = $_SESSION['u_ID'] ?? null;
        $roleId = $_SESSION['role_ID'] ?? null;
        syncPeriodTargets($conn, (int)($_POST['period_ID'] ?? 0), $_POST['pe_target_ID'] ?? '', $cohortList, $classList, $modeValue, $userId, $roleId, true);
        
        // 如果是 AJAX 請求，返回 JSON；否則重定向
        if (isAjaxRequest()) {
            jsonResponse(['success' => true, 'msg' => '已更新評分時段']);
        }
        header("Location: checkreviewperiods.php?sort=$sort");
        exit;
    } catch (Exception $e) {
        if (isAjaxRequest()) {
            jsonResponse([
                'success' => false,
                'msg' => '更新評分時段失敗：' . $e->getMessage()
            ], 200);
        }
        throw $e;
    }
}

/* CRUD: delete */
if (($_POST['action'] ?? '') === 'delete') {
    $periodId = (int)($_POST['period_ID'] ?? 0);
    if ($periodId > 0) {
        $stmt = $conn->prepare("DELETE FROM perioddata WHERE period_ID=?");
        $stmt->execute([$periodId]);
    }
    if (isAjaxRequest()) {
        jsonResponse(['success' => true, 'msg' => '已刪除評分時段']);
    }
    header("Location: checkreviewperiods.php?sort=$sort");
    exit;
}

if (($_POST['action'] ?? '') === 'toggle_status') {
    $periodId = (int)($_POST['period_ID'] ?? 0);
    $targetStatus = (int)($_POST['target_status'] ?? 0) === 1 ? 1 : 0;
    $success = false;
    if (!$periodStatusColumn) {
        jsonResponse([
            'success' => false,
            'msg' => '目前資料表沒有狀態欄位可供更新'
        ], 200);
    }
    if ($periodId > 0) {
        $stmt = $conn->prepare("UPDATE perioddata SET {$periodStatusColumn}=? WHERE period_ID=?");
        $success = $stmt->execute([$targetStatus, $periodId]);
    }
    jsonResponse([
        'success' => $success,
        'status' => $targetStatus,
        'period_ID' => $periodId
    ], 200);
}

/* 取得班級 */
if (isset($_GET['class_list'])) {

  ob_clean();
  header('Content-Type: application/json; charset=utf-8');

  try {
      $role_ID = $_SESSION['role_ID'] ?? null;
      $u_ID = $_SESSION['u_ID'] ?? null;
      
      // 角色 1 或 2：顯示所有班級
      if (in_array($role_ID, [1, 2])) {
          $stmt = $conn->prepare("
              SELECT c_ID, c_name
              FROM classdata
              ORDER BY c_ID ASC
          ");
          $stmt->execute();
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
      }
      // 角色 3：只顯示自己班級的班級
      elseif ($role_ID == 3 && $u_ID) {
          $stmt = $conn->prepare("
              SELECT DISTINCT c.c_ID, c.c_name
              FROM classdata c
              JOIN enrollmentdata e ON e.class_ID = c.c_ID
              WHERE e.enroll_u_ID = ? 
                AND e.enroll_status = 1
              ORDER BY c.c_ID ASC
          ");
          $stmt->execute([$u_ID]);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
      }
      // 角色 4：顯示自己指導團隊所屬的班級
      elseif ($role_ID == 4 && $u_ID) {
          // teammember 裡的 user 欄位名稱可能是 team_u_ID 或 u_ID
          $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
          $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';
          
          // 先獲取指導老師的團隊ID列表
          $stmt = $conn->prepare("
              SELECT DISTINCT tm.team_ID
              FROM teammember tm
              JOIN userrolesdata ur ON ur.ur_u_ID = tm.{$teamUserField}
              JOIN teamdata t ON t.team_ID = tm.team_ID
              WHERE tm.{$teamUserField} = ?
                AND ur.role_ID = 4
                AND ur.user_role_status = 1
                AND t.team_status = 1
          ");
          $stmt->execute([$u_ID]);
          $teacherTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
          
          if (empty($teacherTeamIds)) {
              echo json_encode([], JSON_UNESCAPED_UNICODE);
              exit;
          }
          
          // 獲取這些團隊中學生所屬的班級
          $phTeam = implode(',', array_fill(0, count($teacherTeamIds), '?'));
          $stmt = $conn->prepare("
              SELECT DISTINCT c.c_ID, c.c_name
              FROM classdata c
              JOIN enrollmentdata e ON e.class_ID = c.c_ID
              JOIN teammember tm ON tm.{$teamUserField} = e.enroll_u_ID
              WHERE tm.team_ID IN ($phTeam)
                AND e.enroll_status = 1
              ORDER BY c.c_ID ASC
          ");
          $stmt->execute($teacherTeamIds);
          echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
      }
      // 其他角色：返回空陣列
      else {
          echo json_encode([], JSON_UNESCAPED_UNICODE);
      }
  } catch (Exception $e) {
      echo json_encode([], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* 取得屆別 */
if (isset($_GET['cohort_list'])) {

  ob_clean(); // 🔥 清除之前所有 output（防止 BOM）
  header('Content-Type: application/json; charset=utf-8');

  try {
      $role_ID = $_SESSION['role_ID'] ?? null;
      $u_ID = $_SESSION['u_ID'] ?? null;
      
      // 角色 1 或 2：顯示所有屆別
      if (in_array($role_ID, [1, 2])) {
          $stmt = $conn->prepare("
              SELECT
                  cohort_ID,
                  cohort_name,
                  year_label
              FROM cohortdata
              WHERE cohort_status = 1
              ORDER BY cohort_ID ASC
          ");
          $stmt->execute();
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($rows, JSON_UNESCAPED_UNICODE);
      }
      // 角色 3：只顯示自己班級的屆別
      elseif ($role_ID == 3 && $u_ID) {
          $stmt = $conn->prepare("
              SELECT DISTINCT c.cohort_ID, c.cohort_name, c.year_label
              FROM cohortdata c
              JOIN enrollmentdata e ON e.cohort_ID = c.cohort_ID
              WHERE e.enroll_u_ID = ? 
                AND e.enroll_status = 1
                AND c.cohort_status = 1
              ORDER BY c.cohort_ID ASC
          ");
          $stmt->execute([$u_ID]);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($rows, JSON_UNESCAPED_UNICODE);
      }
      // 角色 4：顯示自己指導團隊所屬的屆別
      elseif ($role_ID == 4 && $u_ID) {
          // teammember 裡的 user 欄位名稱可能是 team_u_ID 或 u_ID
          $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
          $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';
          
          $stmt = $conn->prepare("
              SELECT DISTINCT c.cohort_ID, c.cohort_name, c.year_label
              FROM cohortdata c
              JOIN teamdata t ON t.cohort_ID = c.cohort_ID
              JOIN teammember tm ON tm.team_ID = t.team_ID
              JOIN userrolesdata ur ON ur.ur_u_ID = tm.{$teamUserField}
              WHERE tm.{$teamUserField} = ?
                AND ur.role_ID = 4
                AND ur.user_role_status = 1
                AND t.team_status = 1
                AND c.cohort_status = 1
              ORDER BY c.cohort_ID ASC
          ");
          $stmt->execute([$u_ID]);
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          echo json_encode($rows, JSON_UNESCAPED_UNICODE);
      }
      // 其他角色：返回空陣列
      else {
          echo json_encode([], JSON_UNESCAPED_UNICODE);
      }
  } catch (Exception $e) {
      echo json_encode([], JSON_UNESCAPED_UNICODE);
  }
  exit;
}


/* 取得指定屆別的團隊 */
if (isset($_GET['team_list'])) {

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
  
    $cohortId = $_GET['cohort_id'] ?? '';
    $classId  = $_GET['class_id'] ?? '';
  
    $cohortIds = array_filter(array_map('intval', explode(',', $cohortId)));
    $classIds  = array_filter(array_map('intval', explode(',', $classId)));
  
    if (!$cohortIds) {
        echo json_encode([]);
        exit;
    }
  
    // teammember 裡的 user 欄位名稱可能是 team_u_ID 或 u_ID
    $colsTm = $conn->query("SHOW COLUMNS FROM teammember")->fetchAll(PDO::FETCH_COLUMN);
    $teamUserField = in_array('team_u_ID', $colsTm) ? 'team_u_ID' : 'u_ID';
    
    $role_ID = $_SESSION['role_ID'] ?? null;
    $u_ID = $_SESSION['u_ID'] ?? null;
    
    try {
        // 角色 1 或 2：顯示所有團隊
        if (in_array($role_ID, [1, 2])) {
            // ① 有選班級：透過 join 抓出至少有一名學生成員在該班級的團隊
            if ($classIds) {
                $phCohort = implode(',', array_fill(0, count($cohortIds), '?'));
                $phClass  = implode(',', array_fill(0, count($classIds), '?'));
        
                $sql = "
                    SELECT DISTINCT t.team_ID, t.team_project_name
                    FROM teamdata t
                    JOIN teammember tm ON tm.team_ID = t.team_ID
                    JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
                    WHERE t.team_status = 1
                      AND t.cohort_ID IN ($phCohort)
                      AND e.cohort_ID IN ($phCohort)
                      AND e.class_ID IN ($phClass)
                      AND e.role_ID = 6
                      AND e.enroll_status = 1
                    ORDER BY t.team_project_name ASC
                ";
        
                $stmt = $conn->prepare($sql);
                $stmt->execute(array_merge($cohortIds, $cohortIds, $classIds));
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
            }
            // ② 未選班級：只選屆別 → 直接取全部團隊
            else {
                $phCohort = implode(',', array_fill(0, count($cohortIds), '?'));
                $sql = "
                    SELECT team_ID, team_project_name
                    FROM teamdata
                    WHERE team_status = 1
                      AND cohort_ID IN ($phCohort)
                    ORDER BY team_project_name ASC
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute($cohortIds);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
            }
        }
        // 角色 3：只顯示自己班級的所有團隊
        elseif ($role_ID == 3 && $u_ID) {
            // 先獲取用戶的班級ID
            $stmt = $conn->prepare("
                SELECT DISTINCT class_ID, cohort_ID
                FROM enrollmentdata
                WHERE enroll_u_ID = ? AND enroll_status = 1
            ");
            $stmt->execute([$u_ID]);
            $userClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($userClasses)) {
                echo json_encode([]);
                exit;
            }
            
            $userClassIds = array_filter(array_column($userClasses, 'class_ID'));
            $userCohortIds = array_filter(array_column($userClasses, 'cohort_ID'));
            
            // 如果前端選擇的屆別不在用戶的屆別中，返回空
            if (!empty($cohortIds)) {
                $validCohorts = array_intersect($cohortIds, $userCohortIds);
                if (empty($validCohorts)) {
                    echo json_encode([]);
                    exit;
                }
                $cohortIds = $validCohorts;
            } else {
                $cohortIds = $userCohortIds;
            }
            
            // 如果前端選擇的班級不在用戶的班級中，返回空
            if (!empty($classIds)) {
                $validClasses = array_intersect($classIds, $userClassIds);
                if (empty($validClasses)) {
                    echo json_encode([]);
                    exit;
                }
                $classIds = $validClasses;
            } else {
                $classIds = $userClassIds;
            }
            
            $phCohort = implode(',', array_fill(0, count($cohortIds), '?'));
            $phClass  = implode(',', array_fill(0, count($classIds), '?'));
    
            $sql = "
                SELECT DISTINCT t.team_ID, t.team_project_name
                FROM teamdata t
                JOIN teammember tm ON tm.team_ID = t.team_ID
                JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
                WHERE t.team_status = 1
                  AND t.cohort_ID IN ($phCohort)
                  AND e.class_ID IN ($phClass)
                  AND e.enroll_status = 1
                ORDER BY t.team_project_name ASC
            ";
    
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge($cohortIds, $classIds));
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        }
        // 角色 4：只顯示自己指導的團隊
        elseif ($role_ID == 4 && $u_ID) {
            // 獲取用戶指導的團隊ID列表
            $stmt = $conn->prepare("
                SELECT DISTINCT tm.team_ID
                FROM teammember tm
                JOIN userrolesdata ur ON ur.ur_u_ID = tm.{$teamUserField}
                JOIN teamdata t ON t.team_ID = tm.team_ID
                WHERE tm.{$teamUserField} = ?
                  AND ur.role_ID = 4
                  AND ur.user_role_status = 1
                  AND t.team_status = 1
            ");
            $stmt->execute([$u_ID]);
            $teacherTeamIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'team_ID');
            
            if (empty($teacherTeamIds)) {
                echo json_encode([]);
                exit;
            }
            
            // 過濾：只顯示符合前端選擇的屆別和班級的指導團隊
            $phCohort = implode(',', array_fill(0, count($cohortIds), '?'));
            
            // 如果有選擇班級，進一步過濾：只顯示至少有一個學生成員在所選班級的團隊
            if ($classIds) {
                $phClass = implode(',', array_fill(0, count($classIds), '?'));
                $phTeam = implode(',', array_fill(0, count($teacherTeamIds), '?'));
                // 使用 EXISTS 確保團隊中至少有一個學生成員在所選班級
                $phCohortSub = implode(',', array_fill(0, count($cohortIds), '?'));
                $sql = "
                    SELECT DISTINCT t.team_ID, t.team_project_name
                    FROM teamdata t
                    WHERE t.team_status = 1
                      AND t.cohort_ID IN ($phCohort)
                      AND t.team_ID IN ($phTeam)
                      AND EXISTS (
                          SELECT 1
                          FROM teammember tm
                          JOIN enrollmentdata e ON e.enroll_u_ID = tm.{$teamUserField}
                          WHERE tm.team_ID = t.team_ID
                            AND e.enroll_status = 1
                            AND e.cohort_ID IN ($phCohortSub)
                            AND e.role_ID = 6
                            AND e.class_ID IN ($phClass)
                      )
                    ORDER BY t.team_project_name ASC
                ";
                // 參數順序：cohortIds (WHERE), teacherTeamIds (WHERE), cohortIds (EXISTS), classIds (EXISTS)
                $params = array_merge($cohortIds, $teacherTeamIds, $cohortIds, $classIds);
            } else {
                $phTeam = implode(',', array_fill(0, count($teacherTeamIds), '?'));
                $sql = "
                    SELECT DISTINCT t.team_ID, t.team_project_name
                    FROM teamdata t
                    WHERE t.team_status = 1
                      AND t.cohort_ID IN ($phCohort)
                      AND t.team_ID IN ($phTeam)
                    ORDER BY t.team_project_name ASC
                ";
                $params = array_merge($cohortIds, $teacherTeamIds);
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        }
        // 其他角色：返回空陣列
        else {
            echo json_encode([], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        echo json_encode([], JSON_UNESCAPED_UNICODE);
    }
    exit;
  }
  



/* 取得表格資料 */
// 先檢查 perioddata 表是否有 cohort_ID 欄位
$hasCohortId = false;
try {
    $checkStmt = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'cohort_ID'");
    $hasCohortId = $checkStmt->rowCount() > 0;
} catch (Exception $e) {
    // 如果檢查失敗，假設沒有這個欄位
    $hasCohortId = false;
}

// 檢查是否有 pe_target_ID 欄位
$hasPeTargetId = false;
try {
    $checkStmt2 = $conn->query("SHOW COLUMNS FROM perioddata LIKE 'pe_target_ID'");
    $hasPeTargetId = $checkStmt2->rowCount() > 0;
} catch (Exception $e) {
    $hasPeTargetId = false;
}
$hasPeMode = (bool)$periodModeColumn;

// 檢查是否有 pe_role_ID 欄位，如果沒有則嘗試添加
$hasPeRoleId = periodColumnExists($conn, 'pe_role_ID');
if (!$hasPeRoleId) {
    $hasPeRoleId = ensurePeriodRoleStorage($conn);
}

// 獲取當前登入用戶ID和角色ID，用於過濾只顯示該用戶以當前角色創建的時段
$currentUserId = $_SESSION['u_ID'] ?? null;
$currentRoleId = $_SESSION['role_ID'] ?? null;

// 構建 WHERE 條件：只顯示當前用戶以當前角色創建的時段
$whereConditions = [];
$whereParams = [];
if ($currentUserId) {
    $whereConditions[] = "p.pe_created_u_ID = ?";
    $whereParams[] = $currentUserId;
}
// 如果有 pe_role_ID 欄位且當前角色ID存在，則只顯示該角色創建的資料
// 只顯示 pe_role_ID 等於當前角色ID的資料（不包括 NULL 的舊資料，因為無法確定角色）
if ($hasPeRoleId) {
    if ($currentRoleId !== null && $currentRoleId !== '') {
        // 只顯示 pe_role_ID = 當前角色ID 的資料
        $whereConditions[] = "p.pe_role_ID = ?";
        $whereParams[] = (int)$currentRoleId;
    } else {
        // 如果當前角色ID不存在，則不顯示任何資料（因為無法確定角色）
        $whereConditions[] = "1 = 0"; // 永遠不匹配
    }
}
// 如果沒有 pe_role_ID 欄位，則只過濾用戶ID（顯示所有該用戶創建的資料）
$whereClause = !empty($whereConditions) ? " WHERE " . implode(" AND ", $whereConditions) : "";

if ($hasCohortId || $hasPeMode) {
    $selectedCols = ['p.*'];
    if ($hasCohortId) {
        // 明確選取 p.cohort_ID，避免被 JOIN 的 c.cohort_ID 覆蓋
        $selectedCols[] = 'p.cohort_ID as period_cohort_ID';
        $selectedCols[] = 'c.cohort_name';
        $selectedCols[] = 'c.year_label';
    } else {
        $selectedCols[] = 'NULL as cohort_name';
        $selectedCols[] = 'NULL as year_label';
    }
    if ($hasPeTargetId) {
        $selectedCols[] = "CASE WHEN p.pe_target_ID = 'ALL' THEN NULL ELSE t.team_project_name END as team_project_name";
    } else {
        $selectedCols[] = 'NULL as team_project_name';
    }
    if ($hasPeMode && $periodModeColumn) {
        $selectedCols[] = "p.{$periodModeColumn} as period_mode_value";
    } else {
        $selectedCols[] = "NULL as period_mode_value";
    }
    $sql = "SELECT " . implode(', ', $selectedCols) . "
            FROM perioddata p";
    if ($hasCohortId) {
        // 使用 CAST 確保資料類型匹配，並處理 NULL 值
        $sql .= " LEFT JOIN cohortdata c ON CAST(p.cohort_ID AS CHAR) = CAST(c.cohort_ID AS CHAR) AND p.cohort_ID IS NOT NULL";
    }
    if ($hasPeTargetId) {
        $sql .= " LEFT JOIN teamdata t ON CAST(p.pe_target_ID AS CHAR) = CAST(t.team_ID AS CHAR) AND p.pe_target_ID != 'ALL'";
    }
    $sql .= $whereClause . " $orderBy";
} else {
    if ($hasPeTargetId) {
        $sql = "SELECT p.*, NULL as cohort_name, NULL as year_label,
                       CASE 
                         WHEN p.pe_target_ID = 'ALL' THEN NULL
                         ELSE t.team_project_name
                       END as team_project_name,
                       " . ($hasPeMode && $periodModeColumn ? "p.{$periodModeColumn}" : "NULL") . " as period_mode_value
                FROM perioddata p
                LEFT JOIN teamdata t ON CAST(p.pe_target_ID AS CHAR) = CAST(t.team_ID AS CHAR) 
                    AND p.pe_target_ID != 'ALL'
                $whereClause
                $orderBy";
    } else {
        $sql = "SELECT p.*, NULL as cohort_name, NULL as year_label,
                       NULL as team_project_name,
                       " . ($hasPeMode && $periodModeColumn ? "p.{$periodModeColumn}" : "NULL") . " as period_mode_value
                FROM perioddata p
                $whereClause
                $orderBy";
    }
}

// 分頁參數
$per = 10; // 每頁顯示數量
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per;

// 先獲取總數（從 perioddata 表），也要加入用戶過濾條件
$countSql = "SELECT COUNT(*) FROM perioddata p" . $whereClause;
$countStmt = $conn->prepare($countSql);
if (!empty($whereParams)) {
    $countStmt->execute($whereParams);
} else {
    $countStmt->execute();
}
$total = (int)$countStmt->fetchColumn();

// 計算總頁數
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages); // 確保頁碼不超過總頁數

// 添加分頁限制
$sql .= " LIMIT " . intval($per) . " OFFSET " . intval($offset);

$stmt = $conn->prepare($sql);
if (!empty($whereParams)) {
    $stmt->execute($whereParams);
} else {
    $stmt->execute();
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 解析團隊資訊以供顯示 */
$periodIdList = array_values(array_unique(array_map(function ($item) {
    return (int)($item['period_ID'] ?? 0);
}, $rows)));
$periodTargetMap = fetchPeriodTargetTeams($conn, $periodIdList);

$teamIdSet = [];
foreach ($rows as &$rowItem) {
    try {
        // 確保 _team_payload 始終存在，即使處理失敗
        if (!isset($rowItem['_team_payload'])) {
            $rowItem['_team_payload'] = [
                'assign' => [],
                'receive' => [],
                'is_all' => false,
                'mode' => 'in'
            ];
        }
        
        $payload = parseTeamTarget($rowItem['pe_target_ID'] ?? '');
$rawModeSource = '';
if ($periodModeColumn && isset($rowItem[$periodModeColumn]) && $rowItem[$periodModeColumn] !== null && $rowItem[$periodModeColumn] !== '') {
    $rawModeSource = $rowItem[$periodModeColumn];
} elseif (isset($rowItem['period_mode_value']) && $rowItem['period_mode_value'] !== null && $rowItem['period_mode_value'] !== '') {
    $rawModeSource = $rowItem['period_mode_value'];
} elseif (!empty($rowItem['pe_mode'])) {
    $rawModeSource = $rowItem['pe_mode'];
}
$rawModeNormalized = strtolower(trim($rawModeSource));
$explicitMode = null;
if (in_array($rawModeNormalized, ['cross', 'between', 'inter'], true) || trim($rawModeSource) === '團隊間互評') {
    $explicitMode = 'cross';
} elseif (in_array($rawModeNormalized, ['in', 'inner', 'within'], true) || trim($rawModeSource) === '團隊內互評') {
    $explicitMode = 'in';
}
    $targetInfo = $periodTargetMap[(int)($rowItem['period_ID'] ?? 0)] ?? [
        'team_ids' => [],
        'assign_team_ids' => [],
        'receive_team_ids' => [],
        'cohort_ids' => []
    ];
    
    // 判斷是否為團隊間互評
    $isCrossMode = ($explicitMode === 'cross');
    
    // 在團隊內互評模式下，強制過濾掉 assign_team_ids（因為不應該有 status_ID=1 的記錄）
    // 只使用 receive_team_ids（status_ID=0）作為唯一的資料來源
    if (!$isCrossMode) {
        // 團隊內互評模式：完全忽略 assign_team_ids，只使用 receive_team_ids
        $targetInfo['assign_team_ids'] = [];
        $targetInfo['receive_team_ids'] = array_values(array_unique($targetInfo['receive_team_ids'] ?? []));
        // 重新計算 team_ids，只包含 receive_team_ids（去重後）
        $targetInfo['team_ids'] = $targetInfo['receive_team_ids'];
    }
    
    $cohortValues = array_unique(array_filter($targetInfo['cohort_ids'] ?? []));
    $rowItem['cohort_values'] = implode(',', $cohortValues);
    
    // 在團隊內互評模式下，強制使用資料庫的資料（只使用 receive_team_ids，status_ID=0）
    // 在團隊間互評模式下，如果資料庫有資料，也優先使用資料庫的資料
    // 對於團隊內互評模式，只要資料庫中有任何記錄（不管 status_ID），都使用資料庫的資料（只使用 receive_team_ids）
    $hasDbDataForIn = !$isCrossMode && count($targetInfo['receive_team_ids'] ?? []) > 0;
    $hasDbDataForCross = $isCrossMode && (count($targetInfo['assign_team_ids'] ?? []) > 0 || count($targetInfo['receive_team_ids'] ?? []) > 0);
    $hasDbData = $hasDbDataForIn || $hasDbDataForCross;
    
    // 如果資料庫有資料，優先使用資料庫資料（特別是團隊內互評模式，必須使用資料庫資料）
    if ($hasDbData) {
        if ($isCrossMode) {
            // 團隊間互評：assign 使用 assign_team_ids，receive 使用 receive_team_ids
            $assignFromDb = array_map('strval', $targetInfo['assign_team_ids'] ?? []);
            $receiveFromDb = array_map('strval', $targetInfo['receive_team_ids'] ?? []);
            $payload['assign'] = array_values(array_unique($assignFromDb));
            $payload['receive'] = array_values(array_unique($receiveFromDb));
            $payload['is_all'] = false;
        } else {
            // 團隊內互評：所有團隊都是 status_ID=0，所以只使用 receive_team_ids
            // 在團隊內互評模式下，不應該有 status_ID=1 的記錄，所以忽略 assign_team_ids
            // 完全忽略 pe_target_ID 欄位中的資料，只使用資料庫的 receive_team_ids
            $receiveFromDb = array_map('strval', $targetInfo['receive_team_ids'] ?? []);
            $uniqueTeams = array_values(array_unique($receiveFromDb));
            // 完全重置 payload，只使用資料庫資料
            $payload = [
                'assign' => $uniqueTeams,
                'receive' => $uniqueTeams,
                'is_all' => false
            ];
        }
    } else {
        // 如果資料庫沒有資料，使用 pe_target_ID 的資料
        if ($payload['is_all'] || (!count($payload['assign']) && !count($payload['receive']))) {
            // 沒有資料，不處理
        } else {
            // 確保去重
            if (!$isCrossMode) {
                // 團隊內互評模式下，assign 和 receive 應該是相同的
                $allTeams = array_values(array_unique(array_merge($payload['assign'] ?? [], $payload['receive'] ?? [])));
                if ($allTeams) {
                    $payload['assign'] = $allTeams;
                    $payload['receive'] = $allTeams;
                }
            } else {
                $payload['assign'] = array_values(array_unique($payload['assign'] ?? []));
                $payload['receive'] = array_values(array_unique($payload['receive'] ?? []));
            }
        }
    }
    if (!empty($payload['assign']) || !empty($payload['receive'])) {
        $rowItem['pe_target_ID'] = json_encode([
            'assign' => $payload['assign'],
            'receive' => $payload['receive']
        ], JSON_UNESCAPED_UNICODE);
    }
    // 設置模式值，優先使用從資料庫讀取的 explicitMode，如果沒有則使用預設值
    $rowItem['mode'] = $explicitMode ?? 'in';
    $rowItem['pe_mode'] = $rowItem['mode']; // 也設置 pe_mode，供前端使用
    $payload['mode'] = $rowItem['mode'];
    $rowItem['_team_payload'] = $payload;
        foreach (['assign', 'receive'] as $role) {
            foreach ($payload[$role] as $teamId) {
                $teamId = (string)$teamId;
                if ($teamId !== '') {
                    $teamIdSet[$teamId] = true;
                }
            }
        }
    } catch (Exception $e) {
        // 如果處理過程中出現錯誤，確保 _team_payload 仍然存在
        error_log('處理評分時段資料時發生錯誤: ' . $e->getMessage());
        $rowItem['_team_payload'] = [
            'assign' => [],
            'receive' => [],
            'is_all' => false,
            'mode' => 'in'
        ];
    }
}
unset($rowItem);

$teamNameMap = [];
if (!empty($teamIdSet)) {
    $placeholders = implode(',', array_fill(0, count($teamIdSet), '?'));
    $stmtTeam = $conn->prepare("SELECT team_ID, team_project_name FROM teamdata WHERE team_ID IN ($placeholders)");
    $stmtTeam->execute(array_keys($teamIdSet));
    foreach ($stmtTeam->fetchAll(PDO::FETCH_ASSOC) as $teamRow) {
        $teamNameMap[(string)$teamRow['team_ID']] = $teamRow['team_project_name'];
    }
}

/* Rank by created */
$rankByCreated = [];
$tmp = $rows;
usort($tmp, function($a,$b){
    $c = strcmp($a['pe_created_d'], $b['pe_created_d']);
    return $c ?: ($a['period_ID'] <=> $b['period_ID']);
});
$i=1;
foreach ($tmp as $r) $rankByCreated[$r['period_ID']] = $i++;


/* 回傳表格 HTML */
?>
<table class="table table-sm align-middle table-clean period-table">
  <thead>
    <tr>
      <th class="text-center">標題</th>
      <th class="text-center">屆別</th>
      <th class="text-center">指定團隊</th>
      <th class="text-center">被評分團隊</th>
      <th class="text-center">開始時間</th>
      <th class="text-center">結束時間</th>
      <th class="text-center">操作</th>
    </tr>
  </thead>
  <tbody>
<?php if (empty($rows)): ?>
    <tr>
      <td colspan="7" class="text-center text-muted">目前尚無評分時段資料</td>
    </tr>
<?php else: ?>
<?php foreach ($rows as $r): ?>
    <tr>
      <td class="text-center"><?= htmlspecialchars($r['period_title'] ?? '') ?></td>
      <td class="text-center"><?php
        // 優先使用 petargetdata 表中的 pe_cohort_ID（從 cohort_values 中獲取）
        // 如果沒有，則使用 perioddata 表中的 cohort_ID
        $cohortIds = [];
        $cohortName = $r['cohort_name'] ?? '';
        $yearLabel = $r['year_label'] ?? '';
        
        // 優先從 cohort_values 獲取（來自 petargetdata 表，可能有多個）
        if (!empty($r['cohort_values'])) {
            $cohortValues = explode(',', $r['cohort_values']);
            foreach ($cohortValues as $cv) {
                $cv = trim($cv);
                if ($cv !== '' && is_numeric($cv)) {
                    $cohortIds[] = (int)$cv;
                }
            }
        }
        
        // 如果沒有，則使用 perioddata 表的 cohort_ID
        if (empty($cohortIds)) {
            $cohortId = $r['period_cohort_ID'] ?? $r['cohort_ID'] ?? null;
            if ($cohortId !== null && $cohortId !== '' && $cohortId !== '0') {
                $cohortIds[] = (int)$cohortId;
            }
        }
        
        // 如果有從 JOIN 獲取的屆別名稱，且只有一個屆別，直接使用
        if (!empty($cohortName) && trim($cohortName) !== '' && count($cohortIds) === 1) {
            echo htmlspecialchars($cohortName);
            if ($yearLabel && trim($yearLabel) !== '') {
                echo ' (' . htmlspecialchars($yearLabel) . ')';
            }
        } elseif (!empty($cohortIds)) {
            // 查詢所有屆別的名稱
            try {
                $placeholders = implode(',', array_fill(0, count($cohortIds), '?'));
                $cohortStmt = $conn->prepare("SELECT cohort_ID, cohort_name, year_label FROM cohortdata WHERE cohort_ID IN ($placeholders) ORDER BY cohort_ID ASC");
                $cohortStmt->execute($cohortIds);
                $cohortRows = $cohortStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($cohortRows)) {
                    $cohortLabels = [];
                    foreach ($cohortRows as $cohortRow) {
                        $label = htmlspecialchars($cohortRow['cohort_name']);
                        if (!empty($cohortRow['year_label'])) {
                            $label .= ' (' . htmlspecialchars($cohortRow['year_label']) . ')';
                        }
                        $cohortLabels[] = $label;
                    }
                    echo implode('、', $cohortLabels); // 使用頓號分隔多個屆別
                } else {
                    // 如果查詢不到，顯示屆別ID
                    echo '屆別ID: ' . implode(', ', $cohortIds);
                }
            } catch (Exception $e) {
                echo '屆別ID: ' . implode(', ', $cohortIds);
            }
        } else {
            echo '－';
        }
      ?></td>
      <td class="text-center"><?= htmlspecialchars(describeTeamSelection($r['_team_payload'] ?? ['assign' => [], 'receive' => [], 'is_all' => false, 'mode' => 'in'], 'assign', $teamNameMap)) ?></td>
      <td class="text-center"><?= htmlspecialchars(describeTeamSelection($r['_team_payload'] ?? ['assign' => [], 'receive' => [], 'is_all' => false, 'mode' => 'in'], 'receive', $teamNameMap)) ?></td>
      <td class="text-center"><?= htmlspecialchars($r['period_start_d'] ?? '') ?></td>
      <td class="text-center"><?= htmlspecialchars($r['period_end_d'] ?? '') ?></td>
      <td class="text-center">
        <button class="btn btn-sm btn-outline-primary" 
          onclick='editRow(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)'>編輯</button>

        <form method="post" action="pages/checkreviewperiods_data.php" class="d-inline period-delete-form">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="period_ID" value="<?= $r['period_ID'] ?>">
          <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
          <button class="btn btn-sm btn-outline-danger">刪除</button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
<?php endif; ?>
  </tbody>
</table>
<div class="pager-bar" id="periodPagerBar">
  <span class="active">1</span>
</div>
<?php
// 頁碼資訊（傳遞給前端使用）
$paginationData = [
    'page' => $page,
    'pages' => $pages,
    'total' => $total,
    'per' => $per
];
?>
<script>
  (function() {
    window.periodPaginationData = <?= json_encode($paginationData, JSON_UNESCAPED_UNICODE) ?>;
    // 當表格載入完成後，初始化頁碼（延遲執行確保 DOM 已更新）
    setTimeout(function() {
      if (typeof buildPeriodPager === 'function' && window.periodPaginationData) {
        buildPeriodPager(window.periodPaginationData.page, window.periodPaginationData.pages);
      }
    }, 10);
  })();
</script>
