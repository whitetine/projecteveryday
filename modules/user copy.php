<?php

require_once __DIR__ . '/../includes/node_api_client.php';
global $conn;
$p  = $_POST;
$do = $_GET['do'] ?? '';

switch ($do) {
    // 登入
    case 'login_sub':
        header('Content-Type: application/json; charset=utf-8');

        // ⭐ 每次登入前，先清掉舊的角色資訊，確保多角色用戶必須重新選擇
        unset($_SESSION['role_ID'], $_SESSION['role_name']);

        $acc = trim($p['acc'] ?? '');
        $pas = trim($p['pas'] ?? '');

        if ($acc === '' || $pas === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'code' => 'BAD_REQUEST', 'msg' => '請輸入帳號與密碼']);
            exit;
        }

        // 查帳號
        $st = $conn->prepare("SELECT * FROM userdata WHERE u_ID = ?");
        $st->execute([$acc]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'code' => 'ACCOUNT_NOT_FOUND', 'msg' => '帳號未註冊，請重新確認']);
            exit;
        }

        // 驗證密碼（這裡你們是明碼，沒有 hash）
        if ($user['u_password'] !== $pas) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'code' => 'WRONG_PASSWORD', 'msg' => '密碼錯誤']);
            exit;
        }

        // 驗證成功 → 設 session
        $_SESSION['u_ID']   = $user['u_ID'];
        $_SESSION['u_name'] = $user['u_name'];
        $_SESSION['u_img']  = $user['u_img'] ?? null;

        // 查角色（使用参数化查询）
        $stmt = $conn->prepare("
        SELECT r.role_ID, r.role_name
        FROM userrolesdata ur
        JOIN roledata r ON ur.role_ID = r.role_ID
        WHERE ur.ur_u_ID = ? AND ur.user_role_status = 1
    ");
        $stmt->execute([$user['u_ID']]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($roles);

        if ($count == 1) {
            $_SESSION['role_ID']   = $roles[0]['role_ID'];
            $_SESSION['role_name'] = $roles[0]['role_name'];
            echo json_encode([
                'ok' => true,
                'code' => 'OK',
                'msg' => '登入成功',
                'role_ID' => $roles[0]['role_ID'],
                'role_name' => $roles[0]['role_name']
            ]);
        } elseif ($count > 1) {
            echo json_encode(['ok' => true, 'code' => 'MULTI_ROLE', 'msg' => '登入成功，請選擇登入身分']);
        } else {
            echo json_encode(['ok' => false, 'code' => 'NO_ROLE', 'msg' => '此帳號尚未設定任何角色']);
        }
        exit;


        // 角色清單（啟用）
    case 'role_choose':
        $u_ID = $_SESSION['u_ID'] ?? '';
        if (!$u_ID) {
            echo json_encode([]);
            break;
        }
        $stmt = $conn->prepare("
            SELECT b.role_ID, b.role_name
            FROM userrolesdata a 
            JOIN roledata b ON a.role_ID = b.role_ID
            WHERE a.ur_u_ID = ? AND a.user_role_status = 1
        ");
        $stmt->execute([$u_ID]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        break;

    // 設定角色到 session（相容舊前端：ID/name；也接受 role_ID/role_name）
    // 設定角色到 session（相容舊前端：ID/name；也接受 role_ID/role_name；新增 cohort_ID）
    case 'role_session': {
            $GLOBAL_ROLES = [0, 1, 2, 5];

            $role_ID   = $p["role_ID"] ?? $p["ID"] ?? null;
            $role_name = $p["role_name"] ?? $p["name"] ?? null;

            // cohort 允許 '' / null（全域角色不需要）
            $cohort_ID = $p["cohort_ID"] ?? null;
            $cohort_ID = ($cohort_ID === '' || $cohort_ID === null) ? null : (int)$cohort_ID;

            if (!$role_ID) {
                json_err('缺少角色ID');
            }
            $role_ID = (int)$role_ID;

            // 驗證登入
            $u_ID = $_SESSION['u_ID'] ?? null;
            if (!$u_ID) {
                json_err('請先登入', 'NOT_LOGGED_IN', 401);
            }

            // 1) 先驗證該角色是否屬於當前用戶（userrolesdata）
            $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM userrolesdata ur
        WHERE ur.ur_u_ID = ? AND ur.role_ID = ? AND ur.user_role_status = 1
    ");
            $stmt->execute([$u_ID, $role_ID]);
            if (!(int)$stmt->fetchColumn()) {
                json_err('您沒有此角色權限');
            }

            // 2) 非全域角色：必須選屆別 + 驗證 enrollmentdata
            if (!in_array($role_ID, $GLOBAL_ROLES, true)) {

                if (!$cohort_ID) {
                    json_err('缺少屆別ID（cohort_ID）');
                }

                $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM enrollmentdata e
            JOIN cohortdata c ON e.cohort_ID = c.cohort_ID
            WHERE e.enroll_u_ID = ?
              AND e.role_ID = ?
              AND e.cohort_ID = ?
              AND c.cohort_status = 1
        ");
                $stmt->execute([$u_ID, $role_ID, $cohort_ID]);

                if (!(int)$stmt->fetchColumn()) {
                    json_err('此角色在該屆別沒有有效身分（enrollmentdata 不存在或屆別未啟用）');
                }
            } else {
                // 全域角色不綁屆別，避免殘留上一個 session 的 cohort
                $cohort_ID = null;
            }

            // 3) 寫入 session
            $_SESSION["role_ID"] = $role_ID;
            $_SESSION["role_name"] = $role_name ?: '';

            $_SESSION["cohort_ID"] = $cohort_ID;         // null 代表全屆別模式
            $_SESSION["year_label"] = $p["year_label"] ?? null;
            $_SESSION["cohort_name"] = $p["cohort_name"] ?? null;

            json_ok([
                'role_ID' => $role_ID,
                'role_name' => $_SESSION["role_name"],
                'cohort_ID' => $cohort_ID,
                'year_label' => $_SESSION["year_label"],
                'cohort_name' => $_SESSION["cohort_name"],
                'message' => '角色/屆別設定成功'
            ]);
            break;
        }


        // 更新個人資料（原樣 redirect）
    case 'update_profile':
        $u_ID    = $p['u_ID'] ?? '';
        $gmail   = trim($p['u_gmail'] ?? '');
        $profile = trim($p['u_profile'] ?? '');
        $clear   = isset($p['clear_avatar']) && $p['clear_avatar'] === '1';

        if ($u_ID === '') {
            echo "<script>alert('缺少使用者ID');history.back();</script>";
            exit;
        }

        $old_img = null;
        if ($clear) {
            $stmt = $conn->prepare("SELECT u_img FROM userdata WHERE u_ID = ?");
            $stmt->execute([$u_ID]);
            $old_img = $stmt->fetchColumn();
        }

        $u_img_filename = null;
        if (!$clear && !empty($_FILES['u_img']['name'])) {
            $ext = pathinfo($_FILES['u_img']['name'], PATHINFO_EXTENSION);
            $u_img_filename = 'u_img_' . $u_ID . '_' . time() . '.' . $ext;
            $target_path = 'headshot/' . $u_img_filename;
            if (!move_uploaded_file($_FILES['u_img']['tmp_name'], $target_path)) {
                echo "<script>alert('頭貼上傳失敗');history.back();</script>";
                exit;
            }
        }

        $set    = ['u_gmail = ?', 'u_profile = ?'];
        $params = [$gmail, $profile];

        if ($clear) {
            $set[] = 'u_img = NULL';
        } elseif ($u_img_filename) {
            $set[] = 'u_img = ?';
            $params[] = $u_img_filename;
        }

        $sql = "UPDATE userdata SET " . implode(',', $set) . " WHERE u_ID = ?";
        $params[] = $u_ID;
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ($clear) {
            $_SESSION['u_img'] = null;
            if ($old_img) {
                $path = __DIR__ . '/../headshot/' . $old_img;
                if (is_file($path)) @unlink($path);
            }
        } elseif ($u_img_filename) {
            $_SESSION['u_img'] = $u_img_filename;
        }

        header("Location: main.php#pages/user_profile.php");
        exit;

        // 修改密碼（原樣 redirect）
    case 'update_password':
        $u_ID         = $p['u_ID'] ?? '';
        $old_pass     = $p['old_password'] ?? '';
        $new_pass     = $p['new_password'] ?? '';
        $confirm_pass = $p['confirm_password'] ?? '';

        if ($u_ID === '' || $old_pass === '' || $new_pass === '' || $confirm_pass === '') {
            header("Location: user_profile.php?error=empty&op=$old_pass&np=$new_pass&cp=$confirm_pass");
            exit;
        }

        $stmt = $conn->prepare("SELECT u_password FROM userdata WHERE u_ID = ?");
        $stmt->execute([$u_ID]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['u_password'] !== $old_pass) {
            header("Location: user_profile.php?error=wrongold&op=$old_pass&np=$new_pass&cp=$confirm_pass");
            exit;
        }

        if ($new_pass !== $confirm_pass) {
            header("Location: user_profile.php?error=mismatch&op=$old_pass&np=$new_pass&cp=$confirm_pass");
            exit;
        }

        $stmt = $conn->prepare("UPDATE userdata SET u_password = ? WHERE u_ID = ?");
        $stmt->execute([$new_pass, $u_ID]);

        header("Location: user_profile.php?success=password");
        exit;

        // 檢查 session 是否有效（用於防呆機制）
    case 'check_session':
        header('Content-Type: application/json; charset=utf-8');
        if (isset($_SESSION['u_ID']) && !empty($_SESSION['u_ID'])) {
            echo json_encode(['logged_in' => true, 'u_ID' => $_SESSION['u_ID']]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        exit;

        // 清空 session（用於防呆機制）
    case 'clear_session':
        header('Content-Type: application/json; charset=utf-8');
        // 清空登入相關的 session 資料
        unset($_SESSION['u_ID']);
        unset($_SESSION['u_name']);
        unset($_SESSION['u_img']);
        unset($_SESSION['role_ID']);
        unset($_SESSION['role_name']);
        echo json_encode(['success' => true, 'message' => 'Session cleared']);
        exit;
}
