-- 建立 teacherteamlimit 資料表（老師帶組上限）
-- 若資料庫中缺少此表，請執行此 SQL
-- 來源：projecteverydays 2026第一版

CREATE TABLE IF NOT EXISTS `teacherteamlimit` (
  `ttl_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號',
  `ttl_u_ID` varchar(25) NOT NULL COMMENT '老師ID',
  `cohort_ID` int(11) NOT NULL COMMENT '屆別',
  `max_count` tinyint(4) NOT NULL COMMENT '團隊數量',
  `created_at` datetime DEFAULT current_timestamp() COMMENT '建立時間',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '最後更新時間',
  PRIMARY KEY (`ttl_ID`),
  UNIQUE KEY `uk_teacher_cohort` (`ttl_u_ID`,`cohort_ID`),
  KEY `fk_ttl_cohort` (`cohort_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='老師帶組上限設定';
