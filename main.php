    <?php include "head.php" ?>
    <link rel="stylesheet" href="css/role_switch.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/navbar-marquee.css?v=<?= time() ?>">

    <?php
    session_start();
    include("includes/pdo.php");

    if (!isset($_SESSION['u_ID'])) {
      // 清除可能殘留的 session
      session_destroy();
      echo "<script>
        // 清除所有歷史記錄，防止返回
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', 'index.php');
        }
        alert('請先登入!');
        window.location.href = 'index.php';
      </script>";
      exit;
    }
    
    // 檢查是否有角色，如果沒有則檢查是否有多個角色
    $role_ID = $_SESSION['role_ID'] ?? null;
    if (!$role_ID) {
        $u_ID = $_SESSION['u_ID'];
        
        // 取得啟用中的屆別ID列表
        $stmt = $conn->prepare("
            SELECT cohort_ID 
            FROM cohortdata 
            WHERE cohort_status = 1
        ");
        $stmt->execute();
        $activeCohorts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 計算有效的角色數量（只計算在啟用屆別下有記錄的角色）
        $activeCohortIds = !empty($activeCohorts) ? implode(',', array_map('intval', $activeCohorts)) : '';
        
        // 取得所有啟用的角色
        $stmt = $conn->prepare("
            SELECT r.role_ID, r.role_name
            FROM userrolesdata ur
            JOIN roledata r ON ur.role_ID = r.role_ID
            WHERE ur.ur_u_ID = ? AND ur.user_role_status = 1
            ORDER BY r.role_ID
        ");
        $stmt->execute([$u_ID]);
        $allRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 過濾角色（與 role_select.php 使用相同邏輯）
        $validRoles = [];
        foreach ($allRoles as $role) {
            $r_ID = (int)$role['role_ID'];
            
            // 主任（1）和科辦（2）直接通過
            if (in_array($r_ID, [1, 2])) {
                $validRoles[] = $role;
                continue;
            }
            
            // 如果沒有啟用的屆別，只允許主任和科辦
            if (empty($activeCohorts)) {
                continue;
            }
            
            // 班導（3）和學生（6）：檢查 enrollmentdata
            if (in_array($r_ID, [3, 6])) {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM enrollmentdata 
                    WHERE enroll_u_ID = ? 
                      AND cohort_ID IN ($activeCohortIds)
                      AND role_ID = ?
                      AND enroll_status = 1
                ");
                $stmt->execute([$u_ID, $r_ID]);
                if ($stmt->fetchColumn() > 0) {
                    $validRoles[] = $role;
                }
            }
            
            // 指導老師（4）：檢查 teammember 和 teamdata
            if ($r_ID == 4) {
                $stmt = $conn->query("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
                $hasTeamUId = $stmt->fetch() !== false;
                $teamUserField = $hasTeamUId ? 'team_u_ID' : 'u_ID';
                
                $stmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM teammember tm
                    INNER JOIN teamdata td ON tm.team_ID = td.team_ID
                    WHERE tm.$teamUserField = ? 
                      AND td.cohort_ID IN ($activeCohortIds)
                      AND tm.tm_status = 1
                      AND td.team_status = 1
                ");
                $stmt->execute([$u_ID]);
                if ($stmt->fetchColumn() > 0) {
                    $validRoles[] = $role;
                }
            }
        }
        
        $roleCount = count($validRoles);
        
        if ($roleCount > 1) {
            // 有多個角色，跳轉到角色選擇頁面
            echo "<script>location.href='pages/role_select.php';</script>";
            exit;
        } elseif ($roleCount === 1) {
            // 只有一個角色，自動設置
            $role = $validRoles[0];
            $_SESSION['role_ID'] = $role['role_ID'];
            $_SESSION['role_name'] = $role['role_name'];
            $role_ID = $role['role_ID'];
        } else {
            // 沒有有效的角色
            echo "<script>alert('此帳號目前沒有可用的角色（請確認您的角色是否在進行中的專題屆別下）');location.href='index.php';</script>";
            exit;
        }
    }
    
    $user_name = $_SESSION['u_name'] ?? '未登入';
    $role_name = $_SESSION['role_name'] ?? '無';
    $isAdmin = in_array($role_ID, [1, 2]);
    
    // 檢查學生是否有團隊，如果沒有則導向到專題申請頁面
    // 如果有團隊但還沒填寫專題初審單，則導向到填寫頁面
    if ($role_ID == 6) {
        $u_ID = $_SESSION['u_ID'];
        
        // 檢查 teammember 表結構（兼容兩種版本）
        $teamUserField = 'team_u_ID';
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM teammember LIKE 'team_u_ID'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $teamUserField = 'u_ID';
            }
        } catch (Exception $e) {
            $teamUserField = 'u_ID';
        }
        
        // 檢查是否有團隊（不再只限制 team_status = 1，改為拿到實際狀態後再判斷行為）
        $team_ID = null;
        $teamStatus = 0;
        try {
            $stmt = $conn->prepare("
                SELECT t.team_ID, t.team_status
                FROM teammember tm
                INNER JOIN teamdata t ON tm.team_ID = t.team_ID
                WHERE tm.{$teamUserField} = ?
                  AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                ORDER BY t.team_update_d DESC, t.team_ID DESC
                LIMIT 1
            ");
            $stmt->execute([$u_ID]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($team) {
                $team_ID = (int)$team['team_ID'];
                $teamStatus = (int)($team['team_status'] ?? 0);
            }
        } catch (Exception $e) {
            $team_ID = null;
            $teamStatus = 0;
        }
        
        // 檢查當前頁面
        $currentPage = $_SERVER['PHP_SELF'] ?? '';
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        $currentHash = $_SERVER['REQUEST_URI'] ?? '';
        // 從 hash 中提取頁面路徑
        if (strpos($currentHash, '#') !== false) {
            $hashPart = substr($currentHash, strpos($currentHash, '#') + 1);
            $currentHash = $hashPart;
        }
        // 檢查是否在專題申請頁面
        $isTeamApplyPage = (strpos($currentPage, 'team_apply.php') !== false || 
                           strpos($currentUri, 'team_apply.php') !== false ||
                           strpos($currentHash, 'team_apply.php') !== false);
        $isFormFillPage = (strpos($currentPage, 'student_form_fill.php') !== false || 
                          strpos($currentUri, 'student_form_fill.php') !== false ||
                          strpos($currentHash, 'student_form_fill.php') !== false);
        
        // 如果沒有團隊，強制導向到專題申請頁面
        if (!$team_ID) {
            // 如果不在專題申請頁面，導向到專題申請頁面
            if (!$isTeamApplyPage) {
                echo "<script>
                    (function() {
                        var currentHash = location.hash || '';
                        var isTeamApplyPage = currentHash.indexOf('team_apply.php') !== -1;
                        if (!isTeamApplyPage) {
                            location.href = 'pages/team_apply.php';
                        }
                    })();
                </script>";
                // 如果服務器端判斷不在專題申請頁面，直接導向
                if (!$isTeamApplyPage) {
                    exit;
                }
            }
        } elseif ($team_ID) {
            // 有團隊：依 team_status 不同行為
            // - team_status = 0 或其他未通過狀態：仍導向專題申請頁
            // - team_status = 1：正常流程（可填寫後續表單）
            // - team_status = 3：視為已結案，只鎖功能、不再要求申請單；此處允許直接進系統
            if ($teamStatus === 0) {
                // 視同「尚未完成申請」→ 導向專題申請單
                if (!$isTeamApplyPage) {
                    echo "<script>
                        (function() {
                            var currentHash = location.hash || '';
                            var isTeamApplyPage = currentHash.indexOf('team_apply.php') !== -1;
                            if (!isTeamApplyPage) {
                                location.href = 'pages/team_apply.php';
                            }
                        })();
                    </script>";
                    if (!$isTeamApplyPage) {
                        exit;
                    }
                }
            } elseif ($teamStatus === 1) {
                // 團隊申請已通過，檢查是否還有需要填寫的表單
                try {
                    // 獲取學生的類組資訊
                    $stmt = $conn->prepare("
                        SELECT t.group_ID, t.cohort_ID
                        FROM teamdata t
                        WHERE t.team_ID = ?
                    ");
                    $stmt->execute([$team_ID]);
                    $teamInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$teamInfo) {
                        // 找不到團隊資訊，允許進入（避免卡死）
                        error_log("main.php: 找不到團隊資訊 - team_ID={$team_ID}");
                    } else {
                        $group_ID = $teamInfo['group_ID'];
                        $cohort_ID = $teamInfo['cohort_ID'];
                        
                        // 獲取該類組的所有啟用中表單（符合目標對象條件）
                        // 目標對象：ft_group 為 NULL（不限類組）或匹配當前類組，且屆別範圍符合
                        $stmt = $conn->prepare("
                            SELECT DISTINCT
                                f.form_ID,
                                f.form_name,
                                f.form_updated_d,
                                ft.ft_group,
                                ft.ft_cohort_from,
                                ft.ft_cohort_to
                            FROM formdata f
                            LEFT JOIN formtargetdata ft ON ft.form_ID = f.form_ID
                            WHERE f.form_status = 1
                              AND (f.form_start_d IS NULL OR f.form_start_d <= NOW())
                              AND (f.form_end_d IS NULL OR f.form_end_d >= NOW())
                              AND (
                                  ft.ft_group IS NULL 
                                  OR ft.ft_group = ''
                                  OR FIND_IN_SET(?, REPLACE(ft.ft_group, ' ', '')) > 0
                              )
                              AND (
                                  ft.ft_cohort_from IS NULL 
                                  OR ft.ft_cohort_from <= ?
                              )
                              AND (
                                  ft.ft_cohort_to IS NULL 
                                  OR ft.ft_cohort_to >= ?
                              )
                            ORDER BY f.form_ID ASC
                        ");
                        $stmt->execute([$group_ID, $cohort_ID, $cohort_ID]);
                        $requiredForms = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // 如果沒有需要填寫的表單，直接允許進入系統
                        if (empty($requiredForms)) {
                            // 沒有表單，允許進入系統
                        } else {
                            // 檢查表是否有審核欄位
                            $stmt = $conn->query("SHOW COLUMNS FROM formsubdata LIKE 'fs_review_status'");
                            $hasReviewStatus = $stmt->fetch() !== false;
                            
                            // 檢查每個表單是否已完成且結案
                            $firstIncompleteForm = null;
                            
                            foreach ($requiredForms as $form) {
                                $form_ID = $form['form_ID'];
                                $form_updated_d = $form['form_updated_d']; // 表單最後更新時間
                                
                                // 獲取該表單的提交記錄
                                if ($hasReviewStatus) {
                                    $stmt = $conn->prepare("
                                        SELECT 
                                            fs.fs_ID, 
                                            fs.fs_status, 
                                            fs.fs_review_status, 
                                            fs.fs_remark,
                                            fs.fs_submitted_d,
                                            fs.fs_created_d
                                        FROM formsubdata fs
                                        WHERE fs.form_ID = ? 
                                          AND fs.fs_team_ID = ?
                                        ORDER BY fs.fs_submitted_d DESC, fs.fs_created_d DESC
                                        LIMIT 1
                                    ");
                                } else {
                                    $stmt = $conn->prepare("
                                        SELECT 
                                            fs.fs_ID, 
                                            fs.fs_status, 
                                            fs.fs_remark,
                                            fs.fs_submitted_d,
                                            fs.fs_created_d
                                        FROM formsubdata fs
                                        WHERE fs.form_ID = ? 
                                          AND fs.fs_team_ID = ?
                                        ORDER BY fs.fs_submitted_d DESC, fs.fs_created_d DESC
                                        LIMIT 1
                                    ");
                                }
                                $stmt->execute([$form_ID, $team_ID]);
                                $submission = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                // 檢查是否已正式提交
                                // fs_status = 1 表示暫存（未提交）
                                // fs_status = 0 或 2 都表示已提交（0=正常提交，2=異常/退件）
                                $hasSubmitted = ($submission !== false && (int)$submission['fs_status'] !== 1);
                                
                                // 檢查審核狀態
                                // fs_review_status = 3（已結案）才能進入系統
                                // fs_review_status = 1（已通過但未結案）或 2（退件）或 null/0（待審核）視為未完成
                                $isCompleted = false;
                                $needsResubmit = false;
                                
                                if ($hasSubmitted) {
                                    if ($hasReviewStatus) {
                                        // 有審核欄位，檢查 fs_review_status
                                        $reviewStatus = $submission['fs_review_status'] ?? null;
                                        $reviewStatusInt = $reviewStatus !== null ? (int)$reviewStatus : null;
                                        // 只有 3 = 已結案 才能進入系統
                                        $isCompleted = ($reviewStatusInt === 3);
                                    } else {
                                        // 沒有審核欄位，從 fs_remark 解析
                                        $remark = $submission['fs_remark'] ?? '';
                                        if ($remark) {
                                            $remarkData = json_decode($remark, true);
                                            if (is_array($remarkData) && isset($remarkData['review_status'])) {
                                                $reviewStatusInt = (int)$remarkData['review_status'];
                                                $isCompleted = ($reviewStatusInt === 3);
                                            }
                                        }
                                    }
                                    
                                    // 檢查表單是否有更新（新增題目或修改）
                                    // 如果表單更新時間晚於提交時間，需要重新提交
                                    if ($submission && $form_updated_d) {
                                        $submittedTime = $submission['fs_submitted_d'] ?? $submission['fs_created_d'] ?? null;
                                        if ($submittedTime) {
                                            $formUpdated = strtotime($form_updated_d);
                                            $submitted = strtotime($submittedTime);
                                            // 如果表單更新時間晚於提交時間，標記需要重新提交
                                            if ($formUpdated > $submitted) {
                                                $needsResubmit = true;
                                                $isCompleted = false; // 表單已更新，需要重新提交
                                            } else {
                                                // 即使更新時間沒有變化，也要檢查是否有新增題目
                                                // 檢查提交時的題目數量 vs 當前題目數量
                                                $stmt = $conn->prepare("
                                                    SELECT COUNT(DISTINCT fa.fq_ID) as submitted_count
                                                    FROM formanswerdata fa
                                                    WHERE fa.fs_ID = ?
                                                ");
                                                $stmt->execute([$submission['fs_ID']]);
                                                $submittedCount = $stmt->fetchColumn();
                                                
                                                $stmt = $conn->prepare("
                                                    SELECT COUNT(*) as current_count
                                                    FROM formquestiondata
                                                    WHERE form_ID = ?
                                                ");
                                                $stmt->execute([$form_ID]);
                                                $currentCount = $stmt->fetchColumn();
                                                
                                                // 如果當前題目數量多於已提交的題目數量，需要重新提交
                                                if ($currentCount > $submittedCount) {
                                                    $needsResubmit = true;
                                                    $isCompleted = false;
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                // 如果表單未完成，記錄第一個未完成的表單
                                if (!$isCompleted) {
                                    if ($firstIncompleteForm === null) {
                                        $firstIncompleteForm = [
                                            'form_ID' => $form_ID,
                                            'form_name' => $form['form_name'],
                                            'fs_ID' => $submission ? $submission['fs_ID'] : 0,
                                            'needs_resubmit' => $needsResubmit
                                        ];
                                    }
                                    // 繼續檢查其他表單，但記錄第一個未完成的
                                }
                            }
                            
                            // 如果有未完成的表單，強制導向到第一個未完成的表單填寫頁面
                            if ($firstIncompleteForm !== null) {
                                $form_ID = $firstIncompleteForm['form_ID'];
                                $fs_ID = $firstIncompleteForm['fs_ID'];
                                $redirectUrl = "pages/student_form_fill.php?form_ID={$form_ID}&team_ID={$team_ID}";
                                if ($fs_ID > 0) {
                                    $redirectUrl .= "&fs_ID={$fs_ID}";
                                }
                                
                                // 記錄調試信息
                                error_log("main.php: 檢查表單完成狀態 - form_ID={$form_ID}, team_ID={$team_ID}, fs_ID={$fs_ID}, needs_resubmit=" . ($firstIncompleteForm['needs_resubmit'] ? '1' : '0'));
                                
                                // 檢查當前頁面是否為表單填寫頁面
                                $isFormFillPage = (strpos($currentPage, 'student_form_fill.php') !== false || 
                                                  strpos($currentUri, 'student_form_fill.php') !== false ||
                                                  strpos($currentHash, 'student_form_fill.php') !== false);
                                
                                // 使用 JavaScript 檢查當前 hash，如果不是表單填寫頁面則導向
                                echo "<script>
                                    (function() {
                                        var currentHash = location.hash || '';
                                        var isFormFillPage = currentHash.indexOf('student_form_fill.php') !== -1 || 
                                                             currentHash.indexOf('form_ID={$form_ID}') !== -1;
                                        if (!isFormFillPage) {
                                            location.href = '{$redirectUrl}';
                                        }
                                    })();
                                </script>";
                                // 如果服務器端判斷不在表單填寫頁面，直接導向
                                if (!$isFormFillPage) {
                                    exit;
                                }
                            }
                            // 所有表單都已完成，允許進入系統
                        }
                    }
                } catch (Exception $e) {
                    // 如果查詢失敗，不阻止進入系統（避免系統錯誤導致無法使用）
                    error_log("檢查表單完成狀態時發生錯誤: " . $e->getMessage());
                }
            }
            
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-Hant">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>專題日總彙 - 首頁</title>
      <style>
      /* 確保 project-browse-container 背景是白色 */
      #content .project-browse-container,
      #content:has(.project-browse-container) {
        background: #ffffff !important;
        background-color: #ffffff !important;
        background-image: none !important;
      }
      /* 專題上傳/查看頁面載入時防止水平跑版 */
      #content:has(.project-upload-page) {
        max-width: 100% !important;
        min-width: 0 !important;
        overflow-x: hidden !important;
      }
      </style>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">


    </head>

    <body class="sb-nav-fixed <?= $isAdmin ? 'admin-mode' : 'user-mode' ?>">
      <?php include "nav.php"; ?>


      <div id="layoutSidenav">
        <div id="layoutSidenav_nav" class="<?= $isAdmin ? 'admin-sidenav-container' : '' ?>">
          <nav class="sb-sidenav accordion <?= $isAdmin ? 'sb-sidenav-dark admin-sidenav' : 'sb-sidenav-light' ?>" id="sidenavAccordion">
            <?php include "sidebar.php"; ?>
          </nav>
        </div>
        <main id="content" class="container-fluid py-4"><!-- .load() 塞子頁面 --></main>


      </div>
      <!-- 通知 Modal -->
      <div class="modal fade" id="bell_box" 
      tabindex="-1" 
      aria-labelledby="notificationModalLabel" 
      aria-hidden="true"
      data-bs-backdrop="false"
      data-bs-keyboard="true">
        <div class="modal-dialog modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="notificationModalLabel">通知中心</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>
            <div class="modal-body" id="bellNotificationList">
              <div class="text-center text-muted">
                <p>載入中...</p>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <script>
      // 下載時程表PDF（使用隱藏的iframe，完全不顯示窗口）
      function downloadSchedulePDF(url) {
          console.log('downloadSchedulePDF 被調用', url);
          try {
              // 創建一個完全隱藏的iframe來觸發下載
              const iframe = document.createElement('iframe');
              iframe.style.display = 'none';
              iframe.style.visibility = 'hidden';
              iframe.style.width = '0';
              iframe.style.height = '0';
              iframe.style.border = 'none';
              iframe.style.position = 'absolute';
              iframe.style.left = '-9999px';
              iframe.style.top = '-9999px';
              iframe.src = url;
              
              console.log('創建隱藏 iframe，URL:', url);
              
              // 添加到頁面
              document.body.appendChild(iframe);
              
              // 監聽 iframe 載入完成
              iframe.onload = function() {
                  console.log('iframe 載入完成，PDF 應該正在生成');
              };
              
              iframe.onerror = function() {
                  console.error('iframe 載入錯誤');
                  // 如果 iframe 失敗，使用備選方案
                  if (document.body.contains(iframe)) {
                      document.body.removeChild(iframe);
                  }
                  // 使用臨時連結作為備選方案
                  const link = document.createElement('a');
                  link.href = url;
                  link.target = '_blank';
                  link.style.display = 'none';
                  document.body.appendChild(link);
                  link.click();
                  setTimeout(function() {
                      if (document.body.contains(link)) {
                          document.body.removeChild(link);
                      }
                  }, 100);
              };
              
              // 下載完成後移除iframe（延遲確保下載已開始）
              setTimeout(function() {
                  if (document.body.contains(iframe)) {
                      document.body.removeChild(iframe);
                      console.log('iframe 已移除');
                  }
              }, 10000); // 10秒後移除iframe，確保PDF已下載
          } catch (error) {
              console.error('下載失敗:', error);
              // 如果所有方法都失敗，使用新窗口打開（作為最後的備選方案）
              window.open(url, '_blank');
          }
      }
      
      // 載入通知列表（模態框）
      async function loadNotifications() {
          try {
              const response = await fetch('api.php?do=get_notifications');
              const notifications = await response.json();
              
              const listEl = document.getElementById('bellNotificationList');
              if (!listEl) return;
              
              if (notifications.length === 0) {
                  listEl.innerHTML = '<p class="text-muted text-center">目前沒有通知</p>';
                  return;
              }
              
              let html = '';
              notifications.forEach(notif => {
                  const isRead = notif.is_read == 1;
                  const readClass = isRead ? 'text-muted' : '';
                  
                  // 檢查是否有連結
                  let linkUrl = null;
                  let linkLabel = '查看';
                  if (notif.urls && Array.isArray(notif.urls)) {
                      const linkItem = notif.urls.find(u => u.type === 'link');
                      if (linkItem) {
                          linkUrl = linkItem.url;
                          linkLabel = linkItem.label || '查看';
                      }
                  }
                  
                  html += `
                      <div class="notification-item ${readClass}" data-msg-id="${notif.msg_ID}" style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0; cursor: pointer; position: relative;">
                          <div class="d-flex align-items-start">
                              <span class="me-2">📌</span>
                              <div class="flex-grow-1">
                                  <strong>${notif.msg_title || '通知'}</strong>
                                  <p class="mb-0 mt-1" style="font-size: 0.9rem;">${notif.msg_content || ''}</p>
                                  ${linkUrl ? `<a href="${linkUrl.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')}" target="_blank" class="schedule-view-link mt-2" style="text-decoration: underline; color: #0d6efd; cursor: pointer; display: inline-block;" onclick="event.stopPropagation();">${linkLabel} <i class="fa-solid fa-external-link-alt"></i></a>` : ''}
                                  <small class="text-muted d-block mt-2">${notif.msg_created_d ? new Date(notif.msg_created_d).toLocaleString('zh-TW') : ''}</small>
                              </div>
                              <button type="button" class="btn-close notification-close-btn" data-msg-id="${notif.msg_ID}" onclick="event.stopPropagation(); markNotificationAsRead(${notif.msg_ID}, this);" aria-label="標記為已讀" style="margin-left: 0.5rem; opacity: 0.6; transition: opacity 0.2s;"></button>
                          </div>
                      </div>
                  `;
              });
              
              listEl.innerHTML = html;
              
              // 綁定下載按鈕事件（使用事件委託）
              listEl.addEventListener('click', function(e) {
                  const btn = e.target.closest('.schedule-download-btn');
                  if (btn) {
                      e.stopPropagation();
                      e.preventDefault();
                      let url = btn.getAttribute('data-url');
                      console.log('下載按鈕被點擊', { url, hasFunction: typeof downloadSchedulePDF });
                      if (url) {
                          // 還原 URL 中的 HTML 實體
                          url = url.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                          
                          // 確保 URL 包含 download=1 參數
                          if (url.includes('schedule_export.php')) {
                              if (!url.includes('download=1')) {
                                  // 如果沒有 download=1，添加它
                                  if (url.includes('?')) {
                                      url += '&download=1';
                                  } else {
                                      url += '?download=1';
                                  }
                                  console.log('自動添加 download=1 參數');
                              }
                          }
                          
                          console.log('準備下載', url);
                          downloadSchedulePDF(url);
                      }
                  }
              });
              
              // 點擊通知跳轉到最新消息頁面
              listEl.querySelectorAll('.notification-item').forEach(item => {
                  item.addEventListener('click', function(e) {
                      // 如果點擊的是下載按鈕，不執行跳轉
                      if (e.target.closest('.schedule-download-btn')) {
                          return;
                      }
                      
                      // 關閉 modal
                      const modal = bootstrap.Modal.getInstance(document.getElementById('bell_box'));
                      if (modal) {
                          modal.hide();
                      }
                      
                      // 跳轉到最新消息頁面
                      if (location.hash) {
                          location.hash = '#pages/new.php';
                      } else {
                          window.location.href = 'main.php#pages/new.php';
                      }
                  });
              });
          } catch (error) {
              console.error('載入通知失敗:', error);
              const listEl = document.getElementById('bellNotificationList');
              if (listEl) {
                  listEl.innerHTML = '<p class="text-danger text-center">載入通知失敗</p>';
              }
          }
      }
      
      // 切換角色
      async function switchRole(role_ID, role_name) {
          try {
              const formData = new FormData();
              formData.append('role_ID', role_ID);
              formData.append('role_name', role_name);

              const response = await fetch('api.php?do=role_session', {
                  method: 'POST',
                  body: formData
              });

              const data = await response.json();
              
              if (data) {
                  // 成功，先清空 content 並清除 hash
                  const contentEl = document.querySelector('#content');
                  if (contentEl) {
                      contentEl.innerHTML = '';
                  }
                  
                  // 清除 hash，回到主頁面
                  if (location.hash) {
                      location.hash = '';
                  }
                  
                  // 卸載 Vue 應用（如果有）
                  if (window.currentApp && typeof window.currentApp.unmount === 'function') {
                      try {
                          window.currentApp.unmount();
                          window.currentApp = null;
                      } catch (e) {
                          console.warn('卸載 Vue 應用時發生錯誤:', e);
                      }
                  }
                  
                  // 顯示成功訊息並重新載入頁面
                  if (window.Swal) {
                      Swal.fire({
                          icon: 'success',
                          title: '切換成功',
                          text: `已切換為「${role_name}」身分`,
                          timer: 1500,
                          showConfirmButton: false
                      }).then(() => {
                          window.location.reload();
                      });
                  } else {
                      alert(`已切換為「${role_name}」身分`);
                      window.location.reload();
                  }
              } else {
                  if (window.Swal) {
                      Swal.fire('錯誤', '切換角色失敗，請重試', 'error');
                  } else {
                      alert('切換角色失敗，請重試');
                  }
              }
          } catch (error) {
              console.error('切換角色失敗:', error);
              if (window.Swal) {
                  Swal.fire('錯誤', '切換角色時發生錯誤', 'error');
              } else {
                  alert('切換角色時發生錯誤');
              }
          }
      }
      
      // 更新通知數量
      async function updateNotificationCount() {
          try {
              const response = await fetch('api.php?do=get_notification_count');
              const data = await response.json();
              const count = parseInt(data.count) || 0;
              
              const badgeEl = document.getElementById('notificationCount');
              if (badgeEl) {
                  if (count > 0) {
                      badgeEl.textContent = count;
                      badgeEl.style.display = 'flex';
                  } else {
                      badgeEl.textContent = '0';
                      badgeEl.style.display = 'none';
                  }
              }
              return count;
          } catch (error) {
              console.error('更新通知數量失敗:', error);
              // 如果API失敗，隱藏badge
              const badgeEl = document.getElementById('notificationCount');
              if (badgeEl) {
                  badgeEl.style.display = 'none';
              }
              return 0;
          }
      }
      
      // 當通知modal打開時載入通知
      const bellBox = document.getElementById('bell_box');
      if (bellBox) {
          bellBox.addEventListener('show.bs.modal', function() {
              loadNotifications();
          });
      }
      
      // 標記通知為已讀並從列表中移除
      async function markNotificationAsRead(msg_ID, closeBtn) {
          try {
              const formData = new FormData();
              formData.append('msg_ID', msg_ID);
              
              const response = await fetch('api.php?do=mark_notification_read', {
                  method: 'POST',
                  body: formData
              });
              
              const result = await response.json();
              
              if (result.ok) {
                  // 從 DOM 中移除該通知
                  const notificationItem = closeBtn.closest('.notification-item');
                  if (notificationItem) {
                      // 添加淡出動畫
                      notificationItem.style.transition = 'opacity 0.3s ease-out';
                      notificationItem.style.opacity = '0';
                      
                      setTimeout(() => {
                          notificationItem.remove();
                          
                          // 檢查是否還有通知
                          const listEl = document.getElementById('bellNotificationList');
                          if (listEl) {
                              const remainingItems = listEl.querySelectorAll('.notification-item');
                              if (remainingItems.length === 0) {
                                  listEl.innerHTML = '<p class="text-muted text-center">目前沒有通知</p>';
                              }
                          }
                          
                          // 更新通知數量
                          updateNotificationCount();
                      }, 300);
                  }
              } else {
                  console.error('標記已讀失敗:', result.msg);
                  if (window.Swal) {
                      Swal.fire({
                          icon: 'error',
                          title: '操作失敗',
                          text: result.msg || '無法標記為已讀',
                          timer: 2000,
                          showConfirmButton: false
                      });
                  }
              }
          } catch (error) {
              console.error('標記已讀錯誤:', error);
              if (window.Swal) {
                  Swal.fire({
                      icon: 'error',
                      title: '連線錯誤',
                      text: '無法連到伺服器',
                      timer: 2000,
                      showConfirmButton: false
                  });
              }
          }
      }
      
      // 頁面載入時更新通知數量
      document.addEventListener('DOMContentLoaded', function() {
          updateNotificationCount();
          // 每30秒更新一次通知數量
          setInterval(updateNotificationCount, 30000);
      });
      </script>

    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
  // 提供當前用戶資訊給前端使用
  window.CURRENT_USER = {
    u_ID: '<?= htmlspecialchars($_SESSION['u_ID'] ?? '', ENT_QUOTES, 'UTF-8') ?>',
    role_ID: <?= (int)($role_ID ?? 0) ?>,
    role_name: '<?= htmlspecialchars($role_name ?? '', ENT_QUOTES, 'UTF-8') ?>'
  };
  
  // ⭐ 防止返回鍵跳轉到登入頁面
  // 使用 history API 確保用戶無法返回到登入頁面
  (function() {
    // 如果當前頁面是 main.php，確保歷史記錄中沒有 login.php
    if (window.history && window.history.replaceState) {
      // 如果 URL 中包含 login.php，重定向到 index.php
      if (window.location.href.includes('login.php')) {
        window.history.replaceState(null, '', 'index.php');
        window.location.href = 'index.php';
        return;
      }
      
      // 確保當前頁面在歷史記錄中
      window.history.replaceState(null, '', window.location.href);
    }
    
    // 監聽返回事件
    window.addEventListener('popstate', function(event) {
      // 如果嘗試返回到登入頁面，阻止並重新導向到 main.php
      if (window.location.href.includes('login.php')) {
        window.history.pushState(null, '', 'index.php');
        window.location.href = 'index.php';
      }
    });
  })();
  
  // 如果沒有 hash，根據角色自動設置預設頁面
  (function() {
    if (!location.hash || location.hash === '#') {
      const role_ID = <?= (int)($role_ID ?? 0) ?>;
      if (role_ID === 3) {
        // 班導師預設頁面為 class.php
        location.hash = 'pages/class.php';
      } else if (role_ID === 4) {
        // 指導老師預設頁面為 teamteacher.php
        location.hash = 'pages/teamteacher.php';
      } else if (role_ID === 6) {
        // 學生預設頁面為 student.php
        location.hash = 'pages/student.php';
      } else {
        // 其他角色預設頁面為 new.php
        location.hash = 'pages/new.php';
      }
    }
  })();
</script>

<style>
  .preview-pane { width:100%; max-width:640px; margin:10px auto 0; }
  .preview-box  { margin:0 auto; }
  .preview-img  { width:100%; height:auto; object-fit:contain; border:1px solid #ddd; border-radius:8px; display:block; }
</style>


<?php include "modules/notify.php"; ?>
<!-- 再載你的 app.js（最後） -->
<script src="js/app.js"></script>
<!-- 學生表單狀態檢查 -->
<script src="js/student_form_check.js"></script>
<script>
// 載入導航欄跑馬燈公告
async function loadNavbarMarquee() {
    const marqueeContainer = document.getElementById('navbarMarquee');
    const marqueeContent = document.getElementById('navbarMarqueeContent');
    
    if (!marqueeContainer || !marqueeContent) return;

    try {
        const response = await fetch('api.php?do=get_marquee_announcements');
        
        // 檢查回應是否為 JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.warn('跑馬燈 API 回應非 JSON:', text.substring(0, 200));
            marqueeContainer.style.display = 'none';
            return;
        }
        
        const data = await response.json();

        if (data.ok && data.announcements && data.announcements.length > 0) {
            // 清空現有內容
            marqueeContent.innerHTML = '';
            
            // 根據優先級排序
            const announcements = data.announcements.sort((a, b) => {
                const priorityA = a.priority || 0;
                const priorityB = b.priority || 0;
                if (priorityB !== priorityA) {
                    return priorityB - priorityA;
                }
                return new Date(b.msg_created_d) - new Date(a.msg_created_d);
            });

            // 生成跑馬燈項目
            announcements.forEach((ann, index) => {
                const item = document.createElement('div');
                item.className = 'navbar-marquee-item';
                
                // 高優先級樣式
                if (ann.priority >= 10) {
                    item.classList.add('priority-high');
                }

                // 圖標
                const iconMap = {
                    'ANNOUNCEMENT': 'fa-bullhorn',
                    'SYSTEM_NOTICE': 'fa-bell',
                    'REMINDER': 'fa-clock'
                };
                const icon = iconMap[ann.msg_type] || 'fa-info-circle';

                // 標題和內容
                let titleHtml = `<span class="navbar-marquee-item-icon"><i class="fa-solid ${icon}"></i></span>`;
                titleHtml += `<span class="navbar-marquee-item-title">${escapeHtml(ann.msg_title)}</span>`;
                
                if (ann.msg_content) {
                    const content = truncateText(ann.msg_content, 30);
                    titleHtml += `<span class="navbar-marquee-item-content">${escapeHtml(content)}</span>`;
                }

                // 檢查是否有連結
                let linkUrl = null;
                if (ann.urls && Array.isArray(ann.urls)) {
                    const linkItem = ann.urls.find(u => u.type === 'link');
                    if (linkItem) {
                        linkUrl = linkItem.url;
                    }
                }

                if (linkUrl) {
                    item.innerHTML = `<a href="${escapeHtml(linkUrl)}" target="_blank" class="navbar-marquee-item-link">${titleHtml} <i class="fa-solid fa-external-link-alt" style="font-size: 0.7rem;"></i></a>`;
                } else {
                    item.innerHTML = titleHtml;
                    item.style.cursor = 'default';
                }

                // 點擊事件（如果有內容，可以顯示詳情）
                if (!linkUrl && ann.msg_content) {
                    item.addEventListener('click', () => {
                        if (window.Swal) {
                            Swal.fire({
                                title: escapeHtml(ann.msg_title),
                                html: `<div style="text-align: left; white-space: pre-wrap;">${escapeHtml(ann.msg_content)}</div>`,
                                icon: 'info',
                                confirmButtonText: '知道了',
                                width: '600px'
                            });
                        } else {
                            alert(ann.msg_title + '\n\n' + ann.msg_content);
                        }
                    });
                }

                marqueeContent.appendChild(item);
                
                // 添加分隔符（除了最後一個）
                if (index < announcements.length - 1) {
                    const separator = document.createElement('div');
                    separator.className = 'navbar-marquee-separator';
                    separator.innerHTML = '•';
                    marqueeContent.appendChild(separator);
                }
            });

            // 如果有多個公告，調整動畫速度
            if (announcements.length > 1) {
                marqueeContent.classList.add('multiple');
            }

            // 顯示跑馬燈
            marqueeContainer.style.display = 'flex';
        } else {
            // 沒有公告，隱藏跑馬燈
            marqueeContainer.style.display = 'none';
        }
    } catch (error) {
        console.error('載入導航欄跑馬燈公告失敗:', error);
        if (marqueeContainer) {
            marqueeContainer.style.display = 'none';
        }
        // 不要讓錯誤阻止頁面正常運作
    }
}

// 確保移除所有殘留的 modal backdrop
function cleanupModalBackdrops() {
    const backdrops = document.querySelectorAll('.modal-backdrop:not(.show)');
    backdrops.forEach(backdrop => {
        backdrop.remove();
    });
    
    // 如果 body 有 modal-open class 但沒有實際的 modal 顯示，移除它
    if (document.body.classList.contains('modal-open')) {
        const visibleModals = document.querySelectorAll('.modal.show');
        if (visibleModals.length === 0) {
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    }
}

// 頁面載入時清理
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', cleanupModalBackdrops);
} else {
    cleanupModalBackdrops();
}

// 定期清理（防止殘留）
setInterval(cleanupModalBackdrops, 1000);

// HTML 轉義函數
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 文字截斷函數
function truncateText(text, maxLength) {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength) + '...';
}

// 頁面載入時載入跑馬燈
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadNavbarMarquee);
} else {
    loadNavbarMarquee();
}

// 當切換頁面時重新載入跑馬燈
if (typeof window.addEventListener !== 'undefined') {
    window.addEventListener('hashchange', () => {
        setTimeout(loadNavbarMarquee, 500);
    });
}
</script>

    </body>

    </html>