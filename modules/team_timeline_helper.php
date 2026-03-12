<?php
/**
 * team_timeline 共用寫入工具
 * - 供會議、組別異動、申請等模組呼叫
 */

if (!function_exists('team_timeline_add_event')) {
    /**
     * 新增一筆時間軸事件（若同 ref_table / ref_ID / action_type 已存在則略過）
     *
     * @param PDO    $conn
     * @param int    $team_ID        組別 ID（必填）
     * @param string $event_type     類型分類（例如：'會議', '組別異動', '申請審核'）
     * @param string $action_type    動作（例如：'新增', '確認', '通過', '退件'）
     * @param string $subject_title  主題（簡短）
     * @param string $event_title    顯示在列表上的標題
     * @param string $event_desc     詳細描述（可為空）
     * @param string $ref_table      來源資料表
     * @param int    $ref_ID         來源主鍵 ID
     * @param string $route_key      前端路由 key（例如：'team_change', 'meeting'）
     * @param string|null $event_datetime  事件時間，預設 NOW()
     * @param string|null $created_by 創建人（u_ID 或名稱）
     */
    function team_timeline_add_event(
        PDO    $conn,
        int    $team_ID,
        string $event_type,
        string $action_type,
        string $subject_title,
        string $event_title,
        string $event_desc,
        string $ref_table,
        int    $ref_ID,
        string $route_key,
        ?string $event_datetime = null,
        ?string $created_by = null
    ): void {
        if ($team_ID <= 0 || $ref_ID <= 0) {
            return;
        }

        // 若沒有 team_timeline 資料表就直接略過（避免在尚未建立表時出錯）
        try {
            $chk = $conn->query("SHOW TABLES LIKE 'team_timeline'");
            if (!$chk || !$chk->fetchColumn()) {
                return;
            }
        } catch (Exception $e) {
            return;
        }

        // 避免重複寫入：以 ref_table + ref_ID + action_type 當作自然 key
        try {
            $stmt = $conn->prepare("
                SELECT 1 FROM team_timeline
                WHERE ref_table = ? AND ref_ID = ? AND action_type = ?
                LIMIT 1
            ");
            $stmt->execute([$ref_table, $ref_ID, $action_type]);
            if ($stmt->fetchColumn()) {
                return;
            }
        } catch (Exception $e) {
            // 查詢失敗時不影響主流程
        }

        if ($event_datetime === null || $event_datetime === '') {
            $event_datetime = date('Y-m-d H:i:s');
        }

        try {
            $ins = $conn->prepare("
                INSERT INTO team_timeline
                (team_ID, event_type, action_type, subject_title, event_title, event_desc,
                 ref_table, ref_ID, route_key, event_datetime, created_by)
                VALUES
                (:team_ID, :event_type, :action_type, :subject_title, :event_title, :event_desc,
                 :ref_table, :ref_ID, :route_key, :event_datetime, :created_by)
            ");
            $ins->execute([
                ':team_ID'        => $team_ID,
                ':event_type'     => $event_type,
                ':action_type'    => $action_type,
                ':subject_title'  => $subject_title,
                ':event_title'    => $event_title,
                ':event_desc'     => $event_desc,
                ':ref_table'      => $ref_table,
                ':ref_ID'         => $ref_ID,
                ':route_key'      => $route_key,
                ':event_datetime' => $event_datetime,
                ':created_by'     => $created_by,
            ]);
        } catch (Exception $e) {
            // 寫入失敗時不擋主流程，可視需要加 log
        }
    }
}

