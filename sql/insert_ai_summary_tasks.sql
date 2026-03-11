-- AI 統整條列式項目 插入至 taskdata（待辦事項）
-- 執行前請確認 taskdata 表結構
-- 若您的 taskdata 有 rd_ID 或 task_meeting_ID，請自行加入對應欄位

INSERT INTO `taskdata` (
  `task_team_ID`, `task_u_ID`, `task_cohort_ID`, `ms_ID`, `req_ID`,
  `task_title`, `task_desc`, `task_start_d`, `task_end_d`,
  `task_done_u_ID`, `task_done_d`, `task_status`, `task_priority`,
  `task_created_d`, `task_url`
) VALUES
(NULL, NULL, NULL, NULL, NULL,
 '系統三大目標：檔案繳交儲存、專題進度追蹤、提升專題效率', NULL,
 NULL, NULL, NULL, NULL, 0, 0, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '檔案管理：範例檔與簽名圖檔上傳、繳交期限提醒', NULL,
 NULL, NULL, NULL, NULL, 0, 0, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '會議紀錄：指導老師與學生出席狀態、會議重點，便於查閱', NULL,
 NULL, NULL, NULL, NULL, 0, 0, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '通知功能：提醒師生繳交初審表等文件', NULL,
 NULL, NULL, NULL, NULL, 0, 0, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '組隊確認：指導老師簽名，簡化科辦統計工作', NULL,
 NULL, NULL, NULL, NULL, 0, 0, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '功能設計複雜，恐增加老師負擔，需進一步簡化', NULL,
 NULL, NULL, NULL, NULL, 0, 1, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '預期成果表、最低專題要求評估等功能尚未完成', NULL,
 NULL, NULL, NULL, NULL, 0, 1, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '系統需進一步優化，確保功能完成度達時程目標', NULL,
 NULL, NULL, NULL, NULL, 0, 1, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '人數與組別統計顯示問題需改善', NULL,
 NULL, NULL, NULL, NULL, 0, 1, NOW(), NULL),

(NULL, NULL, NULL, NULL, NULL,
 '半線上設計：保留紙本簽名，避免老師不便', NULL,
 NULL, NULL, NULL, NULL, 0, 0, NOW(), NULL);
