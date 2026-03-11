-- teamchangeform 表結構範例（若尚未建立）
-- 系辦可透過此表設定：開放/截止時間、開放屆別、申請單名稱、異動類別

-- CREATE TABLE IF NOT EXISTS teamchangeform (
--   tcf_ID int(11) NOT NULL AUTO_INCREMENT,
--   tcf_name varchar(100) NOT NULL COMMENT '申請單名稱（下拉選單顯示）',
--   tcf_cohort_ID int(11) NOT NULL COMMENT '屆別ID',
--   tcf_change_type enum('TEAM_RENAME','TEACHER_CHANGE','MEMBER_ADD','MEMBER_REMOVE','MEMBER_CHANGE') NOT NULL COMMENT '異動類別',
--   tcf_created_u_ID varchar(25) DEFAULT NULL COMMENT '建立者帳號',
--   tcf_open_d datetime DEFAULT NULL COMMENT '開放時間（NULL=立即開放）',
--   tcf_close_d datetime DEFAULT NULL COMMENT '截止時間（NULL=無截止）',
--   tcf_created_d datetime DEFAULT current_timestamp() COMMENT '建立日期',
--   tcf_status tinyint(1) DEFAULT 1 COMMENT '狀態（1=啟用）',
--   PRIMARY KEY (tcf_ID)
-- );

-- 範例：新增一筆 110 屆的專題題目變更申請單，開放 2025-03-01 至 2025-06-30
-- INSERT INTO teamchangeform (tcf_name, tcf_cohort_ID, tcf_change_type, tcf_open_d, tcf_close_d, tcf_status)
-- VALUES ('專題題目變更申請單', 110, 'TEAM_RENAME', '2025-03-01 00:00:00', '2025-06-30 23:59:59', 1);
