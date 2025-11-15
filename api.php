<?php
session_start();
require __DIR__ . '/includes/pdo.php';    // 你原本的 pdo.php
require __DIR__ . '/includes/utils.php';  // 共用工具（下面有內容）

$do = $_GET['do'] ?? '';

switch (true) {
    // 類組管理
    case in_array($do, ['add_group', 'toggle_group']):
        require __DIR__ . '/modules/group.php';
        break;

    // 檔案/模板管理（file.php 會用到）
    case in_array($do, ['get_all_TemplatesFile', 'get_files', 'update_template', 'upload_template', 'listActiveFiles', 
                        'get_files_with_targets', 'upload_file_with_targets', 'update_file_with_targets', 
                        'delete_file', 'batch_delete_files']):
        require __DIR__ . '/modules/file.php';
        break;

    // 使用者 / 角色 / 個資
    case in_array($do, ['login_sub', 'role_choose', 'role_session', 'update_profile', 'update_password']):
        require __DIR__ . '/modules/user.php';
        break;

    // 進度
    case in_array($do, ['select_team', 'select_group', 'new_progress_all']):
        require __DIR__ . '/modules/progress.php';
        break;

    // 互評
    case in_array($do, ['submit_rating', 'get_active_period', 'set_active_period', 'has_rated']):
        require __DIR__ . '/modules/review.php';
        break;


    case in_array($do, ['notify_save']):
        require __DIR__ . '/modules/notify.php';
        break;

    // 里程碑管理
    case in_array($do, ['get_milestones', 'get_requirements', 'get_teams', 'get_requirement_progress',
                        'create_milestone', 'update_milestone', 'delete_milestone', 'approve_milestone']):
        require __DIR__ . '/modules/milestone.php';
        break;

    default:
        // 統一以 JSON 提示未知 action（避免前端誤判「不是 JSON」）
        json_err('Unknown action: ' . $do);
}
