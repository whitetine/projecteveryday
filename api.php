<?php
// 開啟輸出緩衝區，捕獲所有可能的輸出
ob_start();

// 關閉錯誤顯示，確保只輸出 JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 設置 JSON 響應頭（必須在最前面，在任何輸出之前）
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

session_start();
require_once __DIR__ . '/includes/pdo.php';
require_once __DIR__ . '/includes/utils.php';

// 清除輸出緩衝區中的任何內容（包括可能的警告）
ob_clean();
$do = $_GET['do'] ?? '';
switch ($do) {
    case 'get_user_manage_data':
        require __DIR__ . '/modules/admin_usermanage_api.php';
        break;

    case 'create_user':
        require __DIR__ . '/modules/admin_user_create_api.php';
        break;

}

switch (true) {
    // 類組管理
    case in_array($do, ['add_group', 'toggle_group']):
        require __DIR__ . '/modules/group.php';
        break;

    // 檔案/模板管理（file.php 會用到）
    case in_array($do, [
        'get_all_TemplatesFile',
        'get_files',
        'update_template',
        'upload_template',
        'listActiveFiles',
        'get_files_with_targets',
        'upload_file_with_targets',
        'update_file_with_targets',
        'delete_file',
        'batch_delete_files',
        'checkDocSubmission',
        'check_exist'
    ]):
        require __DIR__ . '/modules/file.php';
        break;

    // 使用者 / 角色 / 個資
    case in_array($do, ['login_sub', 'role_choose', 'role_session', 'update_profile', 'update_password', 'check_session', 'clear_session']):
        require __DIR__ . '/modules/user.php';
        break;

    // 進度
    case in_array($do, ['select_team', 'select_group', 'new_progress_all']):
        require __DIR__ . '/modules/progress.php';
        break;

    // 互評
    case in_array($do, [
        'submit_rating',
        'get_active_period',
        'set_active_period',
        'has_rated',
        'get_all_periods',
        'get_teams_to_review',
        'submit_student_rating'
    ]):
        require __DIR__ . '/modules/review.php';
        break;


    // 公告管理
    case in_array($do, ['notify_save', 'notify_list', 'notify_detail', 'notify_update', 'notify_delete', 'notify_publish', 'get_marquee_announcements', 'get_user_announcements', 'mark_announcement_read']):
        require __DIR__ . '/modules/notify_api.php';
        break;

    // 帳號管理
    case in_array($do, ['get_user_manage_data']):
        require __DIR__ . '/modules/admin_usermanage_api.php';
        break;
    // case 'bulk_create_user':
    //     require __DIR__ . '/modules/admin_user_bulk_create_api.php';
    //     break;

    // 通知系統
    case in_array($do, ['get_notifications', 'get_notification_count', 'mark_notification_read', 'mark_notification_unread', 'get_all_notifications', 'delete_notification', 'notify_class_teacher', 'send_schedule_notification', 'send_suggest_notification', 'send_submission_remind']):
        require __DIR__ . '/modules/notification.php';
        break;

    // 里程碑管理
    case in_array($do, [
        'get_milestones',
        'get_requirements',
        'get_teams',
        'get_requirement_progress',
        'create_milestone',
        'update_milestone',
        'delete_milestone',
        'approve_milestone',
        'get_student_milestones',
        'accept_milestone',
        'complete_milestone',
        'get_gantt_data'
    ]):
        require __DIR__ . '/modules/milestone.php';
        break;

    // 組別管理
    case in_array($do, [
        'get_team_management_data', 
        'get_team_detail', 
        'get_filter_options',
        'get_teacher_team_limits',
        'set_teacher_team_limit',
        'get_all_teachers',
        'add_team_member',
        'search_student_team',
        'get_cohort_options',
        'remove_team_member',
        'delete_team',
        'get_available_students'
    ]):
        require __DIR__ . '/modules/team.php';
        break;

    // 組別異動紀錄中心（學生/科辦/主任/老師）
    case in_array($do, [
        'get_my_team_changelog',
        'get_team_change_form_data',
        'get_changelog_list',
        'get_changelog_stats',
        'get_changelog_filter_options',
        'update_changelog_status',
        'edit_changelog',
        'save_change_draft',
        'submit_change_application',
        'reapply_changelog',
        'get_available_students_for_add',
        'get_available_change_forms',
        'create_team_change_form',
        'get_active_cohorts_for_form',
        'get_office_change_forms',
        'update_team_change_form'
    ]):
        require __DIR__ . '/modules/team_change.php';
        break;

    // 專題申請
    case in_array($do, [
        'get_pending_applications',
        'review_application',
        'get_active_cohorts',
        'get_teacher_teams',
        'get_teachers',
        'get_apply_form_config',
        'get_student_info',
        'submit_application',
        'save_draft',
        'get_my_application',
        'get_student_count_by_cohort',
        'get_approved_teams_count_by_cohort',
        'get_no_team_students_count',
        'get_no_team_students',
        'notify_student_gmail',
        'notify_students'
    ]):
        require __DIR__ . '/modules/team_apply.php';
        break;

// 專題申請審核（review）
case in_array($do, [
    'team_apply_review_get_list',
    'team_apply_review_get_detail',
    'team_apply_review_save_remark',
    'team_apply_review_approve',
    'team_apply_review_reject'
]):
    require __DIR__ . '/modules/team_apply_review_api.php';
    break;

    // 系辦專題申請設定管理 API
    case in_array($do, [
        'admin_get_forms',
        'admin_save_form',
        'admin_get_controls',
        'admin_save_control',
        'admin_delete_control',
        'admin_get_limits',
        'admin_save_limit'
    ]):
        require __DIR__ . '/modules/team_apply_admin_api.php';
        break;
    
    // 時程表管理
    case in_array($do, ['get_teams_schedule', 'get_schedule_info', 'save_schedule_info', 'save_team_schedules', 'get_groups']):
        require __DIR__ . '/modules/schedule.php';
        break;

    // 表單管理
    case in_array($do, [
        'get_forms',
        'get_form_detail',
        'save_form',
        'delete_form',
        'get_available_forms',
        'submit_form',
        'get_form_submission',
        'get_team_form',
        'get_form_options',
        'get_option_types',
        'get_form_export_url',
        'get_form_flows',
        'get_form_flow_detail',
        'save_form_flow',
        'update_flow_order',
        'toggle_form_flow',
        'delete_form_flow',
        'get_team_current_form',
        'get_team_flow_progress',
        'recognize_form_questions',
        'test_gemini_connection',
        'get_team_form_submission',
        'get_student_data_for_form',
        'auto_fill_form_with_ai',
        'fill_template_with_recognized_data',
        'fill_template_with_user_data',
        'get_user_data_for_template'
    ]):
        require __DIR__ . '/modules/form.php';
        break;

    // 文件表單管理 (document_forms)
    case in_array($do, [
        'get_document_forms',
        'get_document_form_detail',
        'get_document_form_detail_student',
        'get_document_form_attachment',
        'upload_document_form_attachment',
        'save_document_form',
        'delete_document_form',
        'toggle_document_form_status',
        'search_students',
        'get_teachers_list',
        'get_available_document_forms',
        'submit_document_form',
        'save_document_form_draft',
        'get_document_form_draft',
        'get_document_form_compare_data',
        'get_project_data_for_student',
        'get_grades_list',
        'get_groups_list',
        'get_classes_list',
        'get_cohorts_list'
    ]):
        require __DIR__ . '/modules/document_form.php';
        break;

case 'get_teachers':
    require __DIR__ . '/modules/team_apply_get_teachers.php';
    break;

    // 會議統整
    case in_array($do, [
        'meeting_upload',
        'meeting_transcribe',
        'meeting_summarize',
        'meeting_save',
        'meeting_load',
        'meeting_history',
        'meeting_files',
        'meeting_clear_kind',
        'meeting_delete_record',
        'meeting_upload_text',
        'meeting_ocr_image',
        'meeting_transcribe_audio',
        'delete_meeting',
        'create_meeting',
        'check_in',
        'check_whisperx',
        'teacher_validate',
        'get_attendance'
    ]):
        require __DIR__ . '/modules/meeting_api.php';
        break;

    default:
        // 統一以 JSON 提示未知 action（避免前端誤判「不是 JSON」）
        json_err('Unknown action: ' . $do);
}
