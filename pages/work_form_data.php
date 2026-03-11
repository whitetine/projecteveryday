<?php
//註解
session_start();
require '../includes/pdo.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['u_ID'])) {
    echo json_encode(['success' => false, 'msg' => '尚未登入']);
    exit;
}

date_default_timezone_set('Asia/Taipei');
$u_id  = $_SESSION['u_ID'];
$TABLE = 'workdata';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function response($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 動態偵測使用者欄位（work_u_ID 或 u_ID）
    $userField = 'work_u_ID';
    try {
        $testSt = $conn->query("SHOW COLUMNS FROM $TABLE LIKE 'work_u_ID'");
        if ($testSt->rowCount() === 0) {
            $userField = 'u_ID';
        }
    } catch (Exception $e) {
        $userField = 'u_ID';
    }

    if ($action === 'get') {
        // 檢查資料表是否有 work_created_d 欄位
        $hasCreatedD = false;
        try {
            $testSt = $conn->query("SHOW COLUMNS FROM $TABLE LIKE 'work_created_d'");
            $hasCreatedD = $testSt->rowCount() > 0;
        } catch (Exception $e) {
            // 使用預設值
        }

        // 將前幾天未送出的暫存改為結案
        if ($hasCreatedD) {
            $st = $conn->prepare("UPDATE `$TABLE` 
                                  SET work_status = 3 
                                  WHERE $userField = ? AND work_status = 1 AND DATE(work_created_d) < CURDATE()");
        } else {
            $st = $conn->prepare("UPDATE `$TABLE` 
                                  SET work_status = 3 
                                  WHERE $userField = ? AND work_status = 1 AND DATE(work_update_d) < CURDATE()");
        }
        $st->execute([$u_id]);

        // 取得今日資料（同時檢查 work_created_d 和 work_update_d）
        if ($hasCreatedD) {
            $st = $conn->prepare("SELECT * FROM `$TABLE` 
                                  WHERE $userField = ? 
                                    AND (DATE(work_created_d) = CURDATE() OR DATE(work_update_d) = CURDATE())
                                  ORDER BY work_ID DESC LIMIT 1");
        } else {
            $st = $conn->prepare("SELECT * FROM `$TABLE` 
                                  WHERE $userField = ? AND DATE(work_update_d) = CURDATE()
                                  ORDER BY work_ID DESC LIMIT 1");
        }
        $st->execute([$u_id]);
        $today = $st->fetch(PDO::FETCH_ASSOC);
        $readOnly = $today && intval($today['work_status']) === 3;
        $isDraft = $today && intval($today['work_status']) === 1;

        response([
            'success' => true,
            'work' => $today ?: [],
            'readOnly' => $readOnly,
            'isDraft' => $isDraft
        ]);
    }

    if ($action === 'save' || $action === 'submit') {
        $work_id = trim($_POST['work_id'] ?? '') ?: null; // 確保空字串轉為 null
        $title = trim($_POST['work_title'] ?? '');
        $content = trim($_POST['work_content'] ?? '');
        $status = ($action === 'submit') ? 3 : 1;

        if (empty($title) || empty($content)) {
            response(['success' => false, 'msg' => '標題與內容不得為空']);
        }
        
        // 記錄操作日誌以便調試
        error_log("work_form_data save/submit - work_id: " . ($work_id ?? 'NULL') . ", u_id: $u_id, userField: $userField, action: $action");

        if ($work_id) {
            // 更新現有記錄
            // 先檢查記錄是否存在且屬於當前用戶，以及狀態是否允許修改
            $checkSt = $conn->prepare("SELECT work_status FROM `$TABLE` WHERE work_ID=? AND $userField=? LIMIT 1");
            $checkSt->execute([$work_id, $u_id]);
            $checkRecord = $checkSt->fetch(PDO::FETCH_ASSOC);
            
            if (!$checkRecord) {
                error_log("更新失敗 - 記錄不存在或無權限 - work_ID: $work_id, userField: $userField, u_id: $u_id");
                response(['success' => false, 'msg' => '更新失敗：找不到對應的記錄或無權限']);
            }
            
            // 如果記錄已結案，不允許修改
            if (intval($checkRecord['work_status']) === 3) {
                $actionText = ($action === 'submit') ? '正式送出' : '暫存';
                response(['success' => false, 'msg' => "此記錄已正式送出並結案，無法再{$actionText}。如需修改，請聯繫管理員。"]);
            }
            
            // 執行更新
            $st = $conn->prepare("UPDATE `$TABLE`
                                  SET work_title=?, work_content=?, work_status=?, work_update_d=NOW()
                                  WHERE work_ID=? AND $userField=?");
            $st->execute([$title, $content, $status, $work_id, $u_id]);
            
            if ($st->rowCount() === 0) {
                error_log("更新失敗 - 執行更新時失敗 - work_ID: $work_id, userField: $userField, u_id: $u_id");
                response(['success' => false, 'msg' => '更新失敗：找不到對應的記錄或無權限']);
            }
            // 更新成功，$work_id 保持不變
        } else {
            // 先檢查是否已有今日的記錄（暫存或已送出）
            // 檢查資料表是否有 work_created_d 欄位
            $hasCreatedD = false;
            try {
                $testSt = $conn->query("SHOW COLUMNS FROM $TABLE LIKE 'work_created_d'");
                $hasCreatedD = $testSt->rowCount() > 0;
            } catch (Exception $e) {
                // 使用預設值
            }
            
            // 查詢今日是否已有記錄（查詢所有狀態的記錄）
            if ($hasCreatedD) {
                $st = $conn->prepare("SELECT work_ID, work_status FROM `$TABLE` 
                                      WHERE $userField = ? 
                                        AND (DATE(work_created_d) = CURDATE() OR DATE(work_update_d) = CURDATE())
                                      ORDER BY work_ID DESC LIMIT 1");
            } else {
                $st = $conn->prepare("SELECT work_ID, work_status FROM `$TABLE` 
                                      WHERE $userField = ? 
                                        AND DATE(work_update_d) = CURDATE()
                                      ORDER BY work_ID DESC LIMIT 1");
            }
            $st->execute([$u_id]);
            $existingRecord = $st->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRecord) {
                $existingStatus = intval($existingRecord['work_status']);
                
                // 如果記錄已結案（status=3），不允許再修改
                if ($existingStatus === 3) {
                    $actionText = ($action === 'submit') ? '正式送出' : '暫存';
                    response(['success' => false, 'msg' => "今日已正式送出並結案，無法再{$actionText}。如需修改，請聯繫管理員。"]);
                }
                
                // 如果已有今日記錄且是暫存狀態，更新它
                if ($existingStatus === 1) {
                    $work_id = (int)$existingRecord['work_ID']; // 確保是整數類型
                    
                    // 執行更新（直接更新，因為我們已經確認了記錄存在且狀態正確）
                    $st = $conn->prepare("UPDATE `$TABLE`
                                          SET work_title=?, work_content=?, work_status=?, work_update_d=NOW()
                                          WHERE work_ID=? AND $userField=?");
                    $st->execute([$title, $content, $status, $work_id, $u_id]);
                    
                    if ($st->rowCount() === 0) {
                        // 如果更新失敗，再次檢查記錄是否存在
                        $checkSt = $conn->prepare("SELECT work_ID, work_status, $userField FROM `$TABLE` WHERE work_ID=? LIMIT 1");
                        $checkSt->execute([$work_id]);
                        $checkRecord = $checkSt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$checkRecord) {
                            error_log("更新失敗 - 記錄不存在 - work_ID: $work_id");
                            response(['success' => false, 'msg' => '更新失敗：記錄不存在']);
                        } elseif ($checkRecord[$userField] !== $u_id) {
                            error_log("更新失敗 - 無權限 - work_ID: $work_id, userField: $userField, 記錄的用戶: " . $checkRecord[$userField] . ", 當前用戶: $u_id");
                            response(['success' => false, 'msg' => '更新失敗：無權限修改此記錄']);
                        } else {
                            error_log("更新失敗 - 未知原因 - work_ID: $work_id, userField: $userField, u_id: $u_id, status: " . ($checkRecord['work_status'] ?? 'N/A'));
                            response(['success' => false, 'msg' => '更新失敗：找不到對應的記錄或無權限']);
                        }
                    }
                } else {
                    // 其他狀態（理論上不應該發生），返回錯誤
                    error_log("記錄狀態異常 - work_ID: " . ($existingRecord['work_ID'] ?? 'N/A') . ", status: $existingStatus");
                    response(['success' => false, 'msg' => '記錄狀態異常，無法更新']);
                }
            } else {
                // 沒有今日記錄，插入新記錄
            if ($hasCreatedD) {
                $st = $conn->prepare("INSERT INTO `$TABLE`
                                      ($userField, work_title, work_content, work_status, work_created_d, work_update_d)
                                      VALUES (?, ?, ?, ?, NOW(), NOW())");
            } else {
                $st = $conn->prepare("INSERT INTO `$TABLE`
                                      ($userField, work_title, work_content, work_status, work_update_d)
                                      VALUES (?, ?, ?, ?, NOW())");
            }
            $st->execute([$u_id, $title, $content, $status]);
            
                // 使用 lastInsertId() 來驗證插入是否成功
                $newWorkId = $conn->lastInsertId();
                if (!$newWorkId || $newWorkId == 0) {
                response(['success' => false, 'msg' => '插入失敗：無法建立新記錄']);
                }
                
                // 將新插入的 work_ID 設置為 $work_id，以便後續使用
                $work_id = $newWorkId;
            }
        }

        response([
            'success' => true,
            'msg' => $status == 3 ? '已正式送出並結案' : '已暫存成功',
            'work_id' => $work_id ?? null,
            'reload' => true
        ]);
    }

    response(['success' => false, 'msg' => '未知操作']);
} catch (PDOException $e) {
    error_log('Database error in work_form_data.php: ' . $e->getMessage());
    response(['success' => false, 'msg' => '資料庫錯誤：' . $e->getMessage()]);
} catch (Exception $e) {
    error_log('Error in work_form_data.php: ' . $e->getMessage());
    response(['success' => false, 'msg' => '伺服器錯誤：' . $e->getMessage()]);
}
