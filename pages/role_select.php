<?php

/**
 * 角色 + 屆別 選擇頁面
 */
session_start();
require __DIR__ . '/../includes/pdo.php';

// 檢查是否已登入
if (!isset($_SESSION['u_ID'])) {
    echo '<script>alert("請先登入");location.href="login.php";</script>';
    exit;
}

// 如果已經有角色與屆別（或是全域角色）就直接進
if (!empty($_SESSION['role_ID'])) {
    echo '<script>location.href="../main.php";</script>';
    exit;
}

$u_ID = $_SESSION['u_ID'];
$u_name = $_SESSION['u_name'] ?? '使用者';

// ====== 這段開始：核心：產生「可選的登入身分」清單 ======
$GLOBAL_ROLES = [0, 1, 2, 5]; // 不綁屆別：系統/主任/科辦/訪客

// 1) 先拿 userrolesdata 有啟用的角色（避免 enrollmentdata 有髒資料）
$stmt = $conn->prepare("
    SELECT r.role_ID, r.role_name
    FROM userrolesdata ur
    JOIN roledata r ON ur.role_ID = r.role_ID
    WHERE ur.ur_u_ID = ? AND ur.user_role_status = 1
");
$stmt->execute([$u_ID]);
$userRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) 取啟用屆別（cohort_status=1）
$stmt = $conn->query("
    SELECT cohort_ID, year_label, cohort_name
    FROM cohortdata
    WHERE cohort_status = 1
    ORDER BY cohort_ID
");
$activeCohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$activeCohortIds = array_column($activeCohorts, 'cohort_ID');
$activeCohortIdCsv = implode(',', array_map('intval', $activeCohortIds));

// 3) 可選清單 options：
//    - 全域角色：只有 role
//    - 其他角色：role + cohort（從 enrollmentdata 撈）
$options = [];

// 3-1) 全域角色：只要 userrolesdata 有，就放進 options（不需要 enrollmentdata）
foreach ($userRoles as $r) {
    $rid = (int)$r['role_ID'];
    if (in_array($rid, $GLOBAL_ROLES, true)) {
        $options[] = [
            'role_ID' => $rid,
            'role_name' => $r['role_name'],
            'cohort_ID' => null,
            'year_label' => null,
            'cohort_name' => null,
        ];
    }
}

// 3-2) 其他角色：從 enrollmentdata 撈「該使用者所有啟用屆別下的有效記錄」
//      但要確保該 role 也在 userrolesdata 啟用（避免 enrollmentdata 有但角色沒給）
if (!empty($activeCohortIds)) {
    // $stmt = $conn->prepare("
    //     SELECT DISTINCT 
    //         e.role_ID,
    //         r.role_name,
    //         e.cohort_ID,
    //         c.year_label,
    //         c.cohort_name
    //     FROM enrollmentdata e
    //     JOIN roledata r ON e.role_ID = r.role_ID
    //     JOIN cohortdata c ON e.cohort_ID = c.cohort_ID
    //     JOIN userrolesdata ur 
    //         ON ur.ur_u_ID = e.enroll_u_ID 
    //        AND ur.role_ID = e.role_ID
    //        AND ur.user_role_status = 1
    //     WHERE e.enroll_u_ID = ?
    //       AND e.enroll_status = 1
    //       AND e.role_ID NOT IN (0,1,2,5)
    //       AND e.cohort_ID IN ($activeCohortIdCsv)
    //       AND c.cohort_status = 1
    //     ORDER BY e.role_ID, e.cohort_ID
    // ");
        $stmt = $conn->prepare("
        SELECT DISTINCT 
            e.role_ID,
            r.role_name,
            e.cohort_ID,
            c.year_label,
            c.cohort_name
        FROM enrollmentdata e
        JOIN roledata r ON e.role_ID = r.role_ID
        JOIN cohortdata c ON e.cohort_ID = c.cohort_ID
        JOIN userrolesdata ur 
            ON ur.ur_u_ID = e.enroll_u_ID 
           AND ur.role_ID = e.role_ID
           AND ur.user_role_status = 1
        WHERE e.enroll_u_ID = ?
          AND e.role_ID NOT IN (0,1,2,5)
          AND e.cohort_ID IN ($activeCohortIdCsv)
          AND c.cohort_status = 1
        ORDER BY e.role_ID, e.cohort_ID
    ");
    $stmt->execute([$u_ID]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $options[] = [
            'role_ID' => (int)$row['role_ID'],
            'role_name' => $row['role_name'],
            'cohort_ID' => (int)$row['cohort_ID'],
            'year_label' => $row['year_label'],
            'cohort_name' => $row['cohort_name'],
        ];
    }
}

// 4) 沒有可用選項
if (count($options) === 0) {
    echo '<div class="alert alert-danger">此帳號在啟用屆別下沒有可登入的身分（請檢查 userrolesdata / enrollmentdata / cohort_status）</div>';
    exit;
}

// 5) 只有一個選項就自動登入
if (count($options) === 1) {
    $_SESSION['role_ID'] = $options[0]['role_ID'];
    $_SESSION['role_name'] = $options[0]['role_name'];
    $_SESSION['cohort_ID'] = $options[0]['cohort_ID'];       // 全域角色會是 null
    $_SESSION['year_label'] = $options[0]['year_label'];
    $_SESSION['cohort_name'] = $options[0]['cohort_name'];
    echo '<script>location.href="../main.php";</script>';
    exit;
}
// ====== 這段結束 ======
?>

<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>選擇登入身分</title>
    <?php include "../head.php"; ?>
    <link rel="stylesheet" href="../css/login.css?v=<?= time() ?>">
    <style>
        body {
            background: #0f0f0f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            position: relative;
            overflow: hidden;
        }

        @media (max-height: 800px) {
            body {
                padding: 0.75rem;
                align-items: flex-start;
                padding-top: 2rem;
            }
        }

        @media (max-height: 600px) {
            body {
                padding: 0.5rem;
                padding-top: 1rem;
            }
        }

        /* 登入頁面背景效果 */
        #techbg-host {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .fx-background {
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none;
        }

        .wave {
            position: absolute;
            bottom: 0;
            width: 100%;
            height: 100px;
            background: linear-gradient(45deg, rgba(255, 255, 255, .08), rgba(100, 149, 237, .10));
            border-radius: 50% 50% 0 0;
            animation: wave 10s ease-in-out infinite;
        }

        .wave1 {
            animation-delay: 0s;
            opacity: .6;
        }

        .wave2 {
            animation-delay: 2s;
            opacity: .4;
            height: 120px;
        }

        .wave3 {
            animation-delay: 4s;
            opacity: .3;
            height: 80px;
        }

        @keyframes wave {

            0%,
            100% {
                transform: translateX(0) translateY(0);
            }

            50% {
                transform: translateX(-25%) translateY(-20px);
            }
        }

        .particles {
            position: absolute;
            inset: 0;
            z-index: 2;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, .5);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(100vh) scale(0);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100px) scale(1);
                opacity: 0;
            }
        }

        .role-select-container {
            background: rgba(255, 255, 255, .08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .35);
            padding: 2rem 2.5rem;
            max-width: 550px;
            width: 100%;
            position: relative;
            z-index: 3;
            max-height: 90vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        @media (max-height: 800px) {
            .role-select-container {
                padding: 1.5rem 2rem;
                max-height: 95vh;
            }
        }

        @media (max-height: 600px) {
            .role-select-container {
                padding: 1rem 1.5rem;
            }

            .role-select-title h1 {
                font-size: 1.5rem;
            }

            .role-select-title p {
                font-size: 0.9rem;
            }
        }

        .role-select-title {
            text-align: center;
            margin-bottom: 1.5rem;
            flex-shrink: 0;
        }

        .role-select-title h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.5rem;
        }

        .role-select-title p {
            color: rgba(255, 255, 255, .85);
            font-size: 0.95rem;
        }

        .role-list {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        @media (max-height: 800px) {
            .role-list {
                gap: 0.6rem;
                margin-bottom: 1rem;
            }
        }

        @media (max-height: 600px) {
            .role-select-title {
                margin-bottom: 1rem;
            }

            .role-list {
                gap: 0.5rem;
                margin-bottom: 0.75rem;
            }
        }

        .role-card {
            border: 2px solid rgba(255, 255, 255, .3);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, .1);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }

        @media (max-height: 800px) {
            .role-card {
                padding: 0.85rem 1rem;
            }
        }

        @media (max-height: 600px) {
            .role-card {
                padding: 0.7rem 0.9rem;
                gap: 0.75rem;
            }
        }

        .role-card:hover {
            border-color: rgba(255, 255, 255, .5);
            background: rgba(255, 255, 255, .15);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .3);
        }

        .role-card.selected {
            border-color: #4a90e2;
            background: linear-gradient(135deg, rgba(74, 144, 226, .3) 0%, rgba(53, 122, 189, .3) 100%);
            color: white;
            box-shadow: 0 0 20px rgba(74, 144, 226, .4);
        }

        .role-card.selected .role-icon {
            color: #fff;
        }

        .role-card.selected .role-name {
            color: #fff;
        }

        .role-icon {
            font-size: 2rem;
            color: rgba(255, 255, 255, .9);
            flex-shrink: 0;
        }

        @media (max-height: 800px) {
            .role-icon {
                font-size: 1.75rem;
            }
        }

        @media (max-height: 600px) {
            .role-icon {
                font-size: 1.5rem;
            }
        }

        .role-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: rgba(255, 255, 255, .95);
            margin: 0;
            flex: 1;
        }

        @media (max-height: 800px) {
            .role-name {
                font-size: 1rem;
            }
        }

        @media (max-height: 600px) {
            .role-name {
                font-size: 0.95rem;
            }
        }

        .btn-submit {
            width: 100%;
            padding: 0.9rem 1rem;
            background: linear-gradient(45deg, #4a90e2, #357abd);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            flex-shrink: 0;
            position: sticky;
            bottom: 0;
            margin-top: auto;
        }

        @media (max-height: 800px) {
            .btn-submit {
                padding: 0.75rem;
                font-size: 0.95rem;
            }
        }

        @media (max-height: 600px) {
            .btn-submit {
                padding: 0.65rem;
                font-size: 0.9rem;
            }
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 144, 226, .4);
        }

        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .loading {
            display: none;
        }

        .loading.show {
            display: inline-block;
        }
    </style>
</head>

<body id="indexbody">
    <!-- 登入頁面背景 -->
    <div id="techbg-host"
        class="position-fixed top-0 start-0 w-100 h-100"
        data-mode="login" data-speed="1.12" data-density="1.35"
        data-contrast="bold"
        style="z-index:0; pointer-events:none;"></div>

    <!-- 波浪和粒子效果 -->
    <div class="fx-background">
        <div class="wave wave1"></div>
        <div class="wave wave2"></div>
        <div class="wave wave3"></div>
        <div class="particles">
            <?php for ($i = 0; $i < 24; $i++): ?>
                <div class="particle" style="left: <?= rand(0, 100) ?>%; animation-delay: <?= rand(0, 5) ?>s;"></div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="role-select-container">
        <div class="role-select-title">
            <h1>選擇登入身分</h1>
            <p>您好，<?= htmlspecialchars($u_name) ?>，請選擇您要使用的身分</p>
        </div>

        <div class="role-list" id="roleList">
            <?php foreach ($options as $opt): ?>

                <div class="role-card"
                    data-role-id="<?= $opt['role_ID'] ?>"
                    data-role-name="<?= htmlspecialchars($opt['role_name']) ?>"
                    data-cohort-id="<?= $opt['cohort_ID'] === null ? '' : (int)$opt['cohort_ID'] ?>"
                    data-year-label="<?= htmlspecialchars($opt['year_label'] ?? '') ?>"
                    data-cohort-name="<?= htmlspecialchars($opt['cohort_name'] ?? '') ?>"
                    onclick="selectRole(this)">
                    <div class="role-icon">
                        <?php
                        $icons = [
                            0 => 'fa-gear',
                            1 => 'fa-user-tie',
                            2 => 'fa-user-shield',
                            3 => 'fa-user-graduate',
                            4 => 'fa-chalkboard-teacher',
                            5 => 'fa-user',
                            6 => 'fa-user-graduate',
                            7 => 'fa-user-crown',
                        ];
                        $icon = $icons[$opt['role_ID']] ?? 'fa-user';
                        ?>
                        <i class="fa-solid <?= $icon ?>"></i>
                    </div>

                    <div style="flex:1; display:flex; flex-direction:column; gap:.25rem;">
                        <h3 class="role-name" style="margin:0;"><?= htmlspecialchars($opt['role_name']) ?></h3>

                        <?php
                        $isDirectorOrOffice = in_array((int)$opt['role_ID'], [1, 2], true);
                        if ($isDirectorOrOffice): ?>
                            <?php /* 主任、科辦不顯示屆別相關選項 */ ?>
                        <?php elseif (!empty($opt['cohort_ID'])): ?>
                            <div style="font-size:.9rem; color:rgba(255,255,255,.85);">
                                <span style="
          display:inline-block;
          padding:.15rem .55rem;
          border:1px solid rgba(255,255,255,.25);
          border-radius:999px;
          background: rgba(255,255,255,.08);
        ">
                                    <?= htmlspecialchars($opt['cohort_name'] ?? ($opt['year_label'] . '級')) ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <div style="font-size:.9rem; color:rgba(255,255,255,.65);">
                                全屆別模式
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>

        <button class="btn-submit" id="submitBtn" onclick="submitRole()" disabled>
            <span class="loading" id="loading">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>
            </span>
            <span>確認進入</span>
        </button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedRole = null;

        function selectRole(element) {
            document.querySelectorAll('.role-card').forEach(card => card.classList.remove('selected'));
            element.classList.add('selected');

            selectedRole = {
                role_ID: element.dataset.roleId,
                role_name: element.dataset.roleName,
                cohort_ID: element.dataset.cohortId || '', // 全域角色 = ''
                year_label: element.dataset.yearLabel || '',
                cohort_name: element.dataset.cohortName || ''
            };

            document.getElementById('submitBtn').disabled = false;
        }


        async function submitRole() {
            if (!selectedRole) {
                if (window.Swal) {
                    Swal.fire('提醒', '請選擇一個身分', 'warning');
                } else {
                    alert('請選擇一個身分');
                }
                return;
            }

            const btn = document.getElementById('submitBtn');
            const loading = document.getElementById('loading');
            btn.disabled = true;
            loading.classList.add('show');

            try {
                const formData = new FormData();
                formData.append('role_ID', selectedRole.role_ID);
                formData.append('role_name', selectedRole.role_name);
                formData.append('cohort_ID', selectedRole.cohort_ID);
                formData.append('year_label', selectedRole.year_label);
                formData.append('cohort_name', selectedRole.cohort_name);

                const response = await fetch('../api.php?do=role_session', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data && data.ok === true) {
                    // 成功，跳轉到主頁面
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'success',
                            title: '設定成功',
                            text: '正在進入系統...',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = '../main.php';
                        });
                    } else {
                        window.location.href = '../main.php';
                    }
                } else {
                    const errorMsg = data.msg || '設定角色失敗，請重試';
                    if (window.Swal) {
                        Swal.fire('錯誤', errorMsg, 'error');
                    } else {
                        alert(errorMsg);
                    }
                    btn.disabled = false;
                    loading.classList.remove('show');
                }
            } catch (error) {
                console.error('錯誤:', error);
                const errorMsg = '發生錯誤，請重試';
                if (window.Swal) {
                    Swal.fire('錯誤', errorMsg, 'error');
                } else {
                    alert(errorMsg);
                }
                btn.disabled = false;
                loading.classList.remove('show');
            }
        }
    </script>
</body>

</html>