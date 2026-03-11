-- 新增 data_status_at 欄位，用於判斷編輯鎖定是否過期（超過 5 分鐘視為過期）
-- 執行方式：在 phpMyAdmin 或 MySQL 中執行此 SQL
-- 若欄位已存在會報錯，可忽略

ALTER TABLE meetingrecordsdata
ADD COLUMN data_status_at DATETIME NULL DEFAULT NULL
COMMENT 'data_status=1 時記錄鎖定時間';
