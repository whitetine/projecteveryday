-- 組別異動紀錄：新增附件圖片欄位
ALTER TABLE teamchangelog ADD COLUMN tc_attachment VARCHAR(255) NULL DEFAULT NULL COMMENT '附件圖片路徑' AFTER tc_reason;
