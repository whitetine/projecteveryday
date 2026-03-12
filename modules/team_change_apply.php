<?php
/**
 * 組別異動「通過」時寫入 teamdata / teammember 的共用邏輯
 * 供 update_changelog_status 與 team_change_mail_action 使用
 */
if (!function_exists('team_change_apply_approve')) {
    function team_change_apply_approve($conn, $changelog, $tmCol) {
        $team_ID = (int)($changelog['tc_team_ID'] ?? 0);
        $change_type = trim($changelog['change_type'] ?? '');
        if ($team_ID <= 0) return;

        if ($change_type === 'TEAM_RENAME' && !empty($changelog['tc_team_name_new'])) {
            $stmt = $conn->prepare("UPDATE teamdata SET team_project_name = ?, team_update_d = NOW() WHERE team_ID = ?");
            $stmt->execute([trim($changelog['tc_team_name_new']), $team_ID]);
        } elseif ($change_type === 'TEACHER_CHANGE') {
            $oldTeacherName = trim($changelog['tc_teacher_old'] ?? '');
            if ($oldTeacherName !== '') {
                $oldStmt = $conn->prepare("
                    SELECT tm.{$tmCol} FROM teammember tm
                    JOIN userdata u ON u.u_ID = tm.{$tmCol}
                    WHERE tm.team_ID = ? AND u.u_name = ? AND (tm.tm_status IS NULL OR tm.tm_status = 1)
                    LIMIT 1
                ");
                $oldStmt->execute([$team_ID, $oldTeacherName]);
                $oldTeacherId = $oldStmt->fetchColumn();
                if ($oldTeacherId) {
                    $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND {$tmCol} = ? AND (tm_status IS NULL OR tm_status = 1)");
                    $upd->execute([$team_ID, $oldTeacherId]);
                }
            }
            $newVal = trim($changelog['tc_teacher_new'] ?? '');
            if ($newVal !== '') {
                $newTeacherId = null;
                $stmtU = $conn->prepare("SELECT u_ID FROM userdata WHERE u_ID = ? LIMIT 1");
                $stmtU->execute([$newVal]);
                $newTeacherId = $stmtU->fetchColumn();
                if (!$newTeacherId) {
                    $stmtN = $conn->prepare("SELECT u_ID FROM userdata WHERE u_name = ? LIMIT 1");
                    $stmtN->execute([$newVal]);
                    $newTeacherId = $stmtN->fetchColumn();
                }
                if ($newTeacherId) {
                    $chk = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND {$tmCol} = ?");
                    $chk->execute([$team_ID, $newTeacherId]);
                    if ($chk->fetchColumn() == 0) {
                        $ins = $conn->prepare("INSERT INTO teammember (team_ID, {$tmCol}, tm_status, tm_updated_d) VALUES (?, ?, 1, NOW())");
                        $ins->execute([$team_ID, $newTeacherId]);
                    }
                }
            }
        } elseif ($change_type === 'MEMBER_ADD' || $change_type === 'MEMBER_CHANGE') {
            $memberId = trim($changelog['tc_member'] ?? '');
            if ($memberId !== '') {
                $chk = $conn->prepare("SELECT COUNT(*) FROM teammember WHERE team_ID = ? AND {$tmCol} = ?");
                $chk->execute([$team_ID, $memberId]);
                if ($chk->fetchColumn() == 0) {
                    $ins = $conn->prepare("INSERT INTO teammember (team_ID, {$tmCol}, tm_status, tm_updated_d) VALUES (?, ?, 1, NOW())");
                    $ins->execute([$team_ID, $memberId]);
                }
            }
        } elseif ($change_type === 'MEMBER_REMOVE') {
            $memberId = trim($changelog['tc_member'] ?? '');
            if ($memberId !== '') {
                $upd = $conn->prepare("UPDATE teammember SET tm_status = 0, tm_updated_d = NOW() WHERE team_ID = ? AND {$tmCol} = ? AND (tm_status IS NULL OR tm_status = 1)");
                $upd->execute([$team_ID, $memberId]);
            }
        }
    }
}
