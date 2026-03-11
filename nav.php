
<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$user_name = $_SESSION['u_name'] ?? '未登入';
$user_img  = $_SESSION['u_img'] ?? null;

$role_ID   = $_SESSION['role_ID']   ?? null;
$role_name = $_SESSION['role_name'] ?? null;
$cohort_ID = $_SESSION['cohort_ID'] ?? null;

$identity_options = [];

if (!empty($_SESSION['u_ID'])) {
    require __DIR__ . '/includes/pdo.php';
    $u_ID = $_SESSION['u_ID'];

    // A) 先用 enrollmentdata 產生 role+cohort（你要的主來源）
    $stmt = $conn->prepare("
        SELECT DISTINCT
            e.role_ID,
            r.role_name,
            e.cohort_ID,
            c.year_label,
            c.cohort_name,
            c.cohort_status,
            e.enroll_status
        FROM enrollmentdata e
        JOIN roledata r ON e.role_ID = r.role_ID
        LEFT JOIN cohortdata c ON e.cohort_ID = c.cohort_ID
        JOIN userrolesdata ur
             ON ur.ur_u_ID = e.enroll_u_ID
            AND ur.role_ID = e.role_ID
            AND ur.user_role_status = 1
        WHERE e.enroll_u_ID = ?
        ORDER BY e.role_ID, e.cohort_ID
    ");
    $stmt->execute([$u_ID]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $GLOBAL_ROLES = [1, 2]; // 主任、科辦不綁屆別，不從 enrollmentdata 加入屆別選項

    foreach ($rows as $row) {
        if (in_array((int)$row['role_ID'], $GLOBAL_ROLES, true)) {
            continue; // 主任、科辦跳過，由 fallback 加入單一「全屆別」選項
        }
        $identity_options[] = [
            'role_ID'       => (int)$row['role_ID'],
            'role_name'     => $row['role_name'],
            'cohort_ID'     => ($row['cohort_ID'] === null ? null : (int)$row['cohort_ID']),
            'year_label'    => $row['year_label'] ?? null,
            'cohort_name'   => $row['cohort_name'] ?? null,
            'cohort_status' => ($row['cohort_status'] === null ? null : (int)$row['cohort_status']),
            'enroll_status' => (int)$row['enroll_status'],
        ];
    }

    // B) fallback：如果 userrolesdata 有啟用角色，但 enrollmentdata 沒資料 → 仍加入「全屆別」
    $stmt = $conn->prepare("
        SELECT r.role_ID, r.role_name
        FROM userrolesdata ur
        JOIN roledata r ON ur.role_ID = r.role_ID
        WHERE ur.ur_u_ID = ? AND ur.user_role_status = 1
        ORDER BY r.role_ID
    ");
    $stmt->execute([$u_ID]);
    $userRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $existsRole = [];
    foreach ($identity_options as $opt) {
        $existsRole[(int)$opt['role_ID']] = true;
    }
    foreach ($userRoles as $r) {
        $rid = (int)$r['role_ID'];
        if (!isset($existsRole[$rid])) {
            $identity_options[] = [
                'role_ID'       => $rid,
                'role_name'     => $r['role_name'],
                'cohort_ID'     => null,
                'year_label'    => null,
                'cohort_name'   => null,
                'cohort_status' => null,
                'enroll_status' => 1,
            ];
        }
    }

    // C) 驗證 session 的 role+cohort 是否存在於 options
    $role_ID   = ($role_ID === null ? null : (int)$role_ID);
    $cohort_ID = ($cohort_ID === null || $cohort_ID === '' ? null : (int)$cohort_ID);

    if ($role_ID !== null) {
        $isValid = false;

        foreach ($identity_options as $opt) {
            $optRole = (int)$opt['role_ID'];
            $optCoh  = ($opt['cohort_ID'] === null ? null : (int)$opt['cohort_ID']);

            if ($optRole === $role_ID && $optCoh === $cohort_ID) {
                $isValid = true;
                $_SESSION['role_name']   = $opt['role_name'];
                $_SESSION['year_label']  = $opt['year_label'];
                $_SESSION['cohort_name'] = $opt['cohort_name'];
                $role_name = $opt['role_name'];
                break;
            }
        }

        // 如果 role 存在但 cohort 沒選（null），而該 role 只有一種 cohort → 自動補
        if (!$isValid && $cohort_ID === null) {
            $candidates = array_values(array_filter($identity_options, fn($x) => (int)$x['role_ID'] === $role_ID));
            if (count($candidates) === 1) {
                $pick = $candidates[0];
                $_SESSION['cohort_ID']   = $pick['cohort_ID'];
                $_SESSION['role_name']   = $pick['role_name'];
                $_SESSION['year_label']  = $pick['year_label'];
                $_SESSION['cohort_name'] = $pick['cohort_name'];
                $cohort_ID = $pick['cohort_ID'];
                $role_name = $pick['role_name'];
                $isValid = true;
            }
        }

        // 仍無效：不要硬清到「整個沒角色」讓 sidebar 空掉
        // 改成：保留 role_ID，但 cohort_ID 清空，逼使用者重新選 cohort
        if (!$isValid) {
            $_SESSION['cohort_ID'] = null;
            $_SESSION['year_label'] = null;
            $_SESSION['cohort_name'] = null;
            $cohort_ID = null;
        }
    }
}

$hasMultipleIdentities = count($identity_options) > 1;
$isAdmin = in_array((int)($role_ID ?? -1), [1,2], true);
?>

    <nav class="navbar navbar-expand-lg fixed-top <?= $isAdmin ? 'navbar-dark admin-navbar' : 'navbar-light bg-light' ?>">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <button class="border-0 me-2 <?= $isAdmin ? 'text-white' : 'text-black' ?>" id="sidebarToggle"><i class="fas fa-bars"></i></button>

                <a class="navbar-brand mb-0 ajax-link <?= $isAdmin ? 'text-white' : 'text-black' ?>"
                    href="<?=
                            ($role_ID == 3) ? '#pages/class.php' : (($role_ID == 4) ? '#pages/teamteacher.php' : (($role_ID == 6) ? '#pages/student.php' : '#pages/new.php'))
                            ?>">
                    <i class="fa-solid <?= $isAdmin ? 'fa-shield-halved' : 'fa-folder-open' ?> me-2"></i>專題系統
                    <?php if ($isAdmin): ?>
                        <span class="badge bg-warning text-dark ms-2">管理員</span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- 跑馬燈公告區塊 -->
            <div id="navbarMarquee" class="navbar-marquee-container" style="display: none; flex: 1; margin: 0 1rem; max-width: 600px;">
                <div class="navbar-marquee-wrapper">
                    <div class="navbar-marquee-content" id="navbarMarqueeContent">
                        <!-- 跑馬燈內容將由 JavaScript 動態載入 -->
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center">
                <form class="d-flex align-items-center gap-2 me-3 mb-0" role="search">
                    <input class="form-control form-control-sm <?= $isAdmin ? 'bg-dark text-white border-secondary' : '' ?>" type="search" placeholder="搜尋" aria-label="搜尋" style="max-width: 200px;">
                    <button class="btn btn-sm <?= $isAdmin ? 'btn-warning' : 'btn-secondary' ?>" type="submit">Search</button>
                </form>
                <div class="position-relative me-3" style="cursor:pointer;" onclick="$('#bell_box').modal('show')">
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle" id="notificationCount" style="display: none;">0</span>
                    <lord-icon src="https://cdn.lordicon.com/bpptgtfr.json"
                        trigger="hover"
                        colors="primary:<?= $isAdmin ? '#ffffff' : '#000000' ?>"
                        style="width:40px;height:40px">
                    </lord-icon>
                </div>

                <!-- 使用者選單（position-relative + z-index 確保 team_change 等頁面不遮擋） -->
                <div class="dropdown position-relative navbar-user-dropdown">
                    <button class="btn btn-link dropdown-toggle d-flex align-items-center <?= $isAdmin ? 'text-white' : 'text-dark' ?>"
                        type="button"
                        id="userMenuBtn"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        style="text-decoration: none; border: none; padding: 0.5rem;">
                        <?php if (!empty($user_img)): ?>
                            <img src="headshot/<?= htmlspecialchars($user_img) ?>"
                                width="32" height="32"
                                class="rounded-circle shadow-sm me-2" style="object-fit:cover;"
                                alt="<?= htmlspecialchars($user_name ?: '') ?>"
                                onerror="this.onerror=null; this.src='https://cdn-icons-png.flaticon.com/512/1144/1144760.png';">
                        <?php else: ?>
                            <img src="https://cdn-icons-png.flaticon.com/512/1144/1144760.png"
                                width="32" height="32"
                                class="rounded-circle shadow-sm me-2" style="object-fit:cover;" alt="User">
                        <?php endif; ?>
                        <span class="fw-semibold"><?= htmlspecialchars($user_name ?: '未登入') ?></span>
                        <?php if ($role_name): ?>
                            <span class="ms-2 small opacity-75">
                                <?= htmlspecialchars($role_name) ?>
                                <?php if (!empty($_SESSION['cohort_name'])): ?>
                                    · <?= htmlspecialchars($_SESSION['cohort_name']) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>

                    </button>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 py-2" aria-labelledby="userMenuBtn">
                        <!-- 上方帳號資訊 -->
                        <li class="px-3 pb-2 small text-muted">
                            <?= htmlspecialchars($_SESSION['u_gmail'] ?? ($_SESSION['u_ID'] ?? '')) ?>
                        </li>

                        <?php if ($hasMultipleIdentities): ?>
                            <li class="px-2 pb-2">
                                <div class="small text-muted mb-2 px-2">切換身分</div>
                                <div class="role-switch-list px-2">
                                    <div class="role-switch-select-wrap">
                                    <select id="identitySwitchSelect" class="form-select form-select-sm role-switch-select" onchange="handleIdentitySwitch(this)" aria-label="切換身分">
                                    <?php foreach ($identity_options as $opt): ?>
                                        <?php
                                        $optRole = (int)$opt['role_ID'];
                                        $optCohort = $opt['cohort_ID']; // null or int
                                        $active = ((int)($_SESSION['role_ID'] ?? -1) === $optRole)
                                            && ((($_SESSION['cohort_ID'] ?? null) === null && $optCohort === null)
                                                || ((int)($_SESSION['cohort_ID'] ?? -1) === (int)($optCohort ?? -1)));

                                        // 小標籤顯示（主任、科辦不顯示屆別）
                                        $isDirectorOrOffice = in_array($optRole, [1, 2], true);
                                        $label = $isDirectorOrOffice ? '' : ($optCohort ? ($opt['cohort_name'] ?: ($opt['year_label'] . '級')) : '全屆別');
                                        $isDisabledCohort = isset($opt['cohort_status']) && (int)$opt['cohort_status'] === 0;
                                        // $isInactiveEnroll = isset($opt['enroll_status']) && (int)$opt['enroll_status'] === 0;
                                        ?>
                                        <?php
                                        $cohortText = $optCohort === null ? '' : (string)(int)$optCohort;
                                        $optionText = $label === '' ? $opt['role_name'] : ($opt['role_name'] . ' · ' . $label . ($isDisabledCohort ? '（屆別停用）' : ''));
                                        $isOptionDisabled = (!$active && $isDisabledCohort);
                                        ?>
                                        <option
                                            value="<?= htmlspecialchars((string)$optRole . '::' . ($cohortText === '' ? 'null' : $cohortText), ENT_QUOTES) ?>"
                                            data-role-id="<?= $optRole ?>"
                                            data-role-name="<?= htmlspecialchars($opt['role_name'], ENT_QUOTES) ?>"
                                            data-cohort-id="<?= htmlspecialchars($cohortText, ENT_QUOTES) ?>"
                                            data-year-label="<?= htmlspecialchars($opt['year_label'] ?? '', ENT_QUOTES) ?>"
                                            data-cohort-name="<?= htmlspecialchars($opt['cohort_name'] ?? '', ENT_QUOTES) ?>"
                                            data-active="<?= $active ? '1' : '0' ?>"
                                            <?= $active ? 'selected' : '' ?>
                                            <?= $isOptionDisabled ? 'disabled' : '' ?>>
                                            <?= htmlspecialchars($optionText) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    </select>
                                    </div>
                                </div>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                        <?php endif; ?>


                        <li>
                            <a class="dropdown-item ajax-link" href="#pages/user_profile.php">
                                <i class="fa-solid fa-address-card me-2"></i> 個人資料
                            </a>
                        </li>
                        <?php if ((int)($role_ID ?? 0) === 2): ?>
                        <li>
                            <a class="dropdown-item ajax-link" href="#pages/admin_notify.php">
                                <i class="fa-solid fa-bell me-2"></i> 公告管理
                            </a>
                        </li>
                        <?php endif; ?>

                        <li>
                            <hr class="dropdown-divider">
                        </li>

                        <!-- 說明（次選單） -->
                        <!-- <li class="dropend dropdown-submenu">
                            <a class="dropdown-item dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                <i class="fa-solid fa-circle-question me-2"></i> 說明
                            </a>
                            <ul class="dropdown-menu shadow border-0 rounded-3 py-2">
                                <li><a class="dropdown-item" href="#" target="_blank">說明中心</a></li>
                                <li><a class="dropdown-item ajax-link" href="#pages/changelog.php">版本說明</a></li>
                                <li><a class="dropdown-item" href="terms.html" target="_blank">條款及政策</a></li>
                                <li><a class="dropdown-item ajax-link" href="#pages/bug_report.php">報告錯誤</a></li>
                                <li><a class="dropdown-item" href="https://example.com/app" target="_blank">下載應用程式</a></li>
                                <li><a class="dropdown-item ajax-link" href="#pages/shortcuts.php">鍵盤快捷鍵</a></li>
                            </ul>
                        </li>
                        
                        <li><hr class="dropdown-divider"></li> -->

                        <li>
                            <a class="dropdown-item text-danger" href="logout.php">
                                <i class="fa-solid fa-arrow-right-from-bracket me-2"></i> 登出
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

<script>
function handleIdentitySwitch(selectEl) {
    if (!selectEl) return;
    const picked = selectEl.options[selectEl.selectedIndex];
    if (!picked) return;
    if (picked.dataset.active === '1') return;

    const roleId = Number(picked.dataset.roleId || 0);
    const roleName = picked.dataset.roleName || '';
    const cohortRaw = (picked.dataset.cohortId || '').trim();
    const cohortId = cohortRaw === '' ? null : Number(cohortRaw);
    const yearLabel = picked.dataset.yearLabel || '';
    const cohortName = picked.dataset.cohortName || '';
    if (!roleId) return;

    switchIdentity(roleId, roleName, cohortId, yearLabel, cohortName);
}
</script>