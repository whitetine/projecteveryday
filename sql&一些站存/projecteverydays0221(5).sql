-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： 127.0.0.1
-- 產生時間： 2025-11-21 07:28:29
-- 伺服器版本： 10.4.32-MariaDB
-- PHP 版本： 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 資料庫： `projecteverydays`
--

-- --------------------------------------------------------

--
-- 資料表結構 `accesslogs`
--

CREATE TABLE `accesslogs` (
  `access_ID` int(11) NOT NULL COMMENT '主鍵',
  `u_ID` varchar(25) DEFAULT NULL COMMENT '使用者',
  `role_ID` int(11) DEFAULT NULL COMMENT '使用角色',
  `ip_address` varchar(45) DEFAULT NULL COMMENT '使用者IP',
  `user_agent` text DEFAULT NULL COMMENT '瀏覽器資訊',
  `access_time` datetime DEFAULT NULL COMMENT '訪問時間',
  `access_type` varchar(50) DEFAULT NULL COMMENT '類型',
  `page_url` text DEFAULT NULL COMMENT '頁面路徑',
  `success` tinyint(1) DEFAULT NULL COMMENT '是否成功'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='登入、使用紀錄';

-- --------------------------------------------------------

--
-- 資料表結構 `actionlogs`
--

CREATE TABLE `actionlogs` (
  `action_ID` int(11) NOT NULL COMMENT '主鍵',
  `u_ID` varchar(25) DEFAULT NULL COMMENT '使用者',
  `role_ID` int(11) DEFAULT NULL COMMENT '使用角色',
  `action_type` varchar(50) NOT NULL COMMENT '動作類型',
  `target_table` varchar(100) DEFAULT NULL COMMENT '動用資料表',
  `target_ID` int(11) DEFAULT NULL COMMENT '動用資料表的ID',
  `action_description` text DEFAULT NULL COMMENT '描述動作',
  `action_time` datetime DEFAULT NULL COMMENT '動作時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='操作行為紀錄';

-- --------------------------------------------------------

--
-- 資料表結構 `classdata`
--

CREATE TABLE `classdata` (
  `c_ID` int(11) NOT NULL COMMENT '班級ID',
  `c_name` varchar(10) NOT NULL COMMENT '名稱'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='班級';

--
-- 傾印資料表的資料 `classdata`
--

INSERT INTO `classdata` (`c_ID`, `c_name`) VALUES
(1, '忠'),
(2, '孝');

-- --------------------------------------------------------

--
-- 資料表結構 `cohortdata`
--

CREATE TABLE `cohortdata` (
  `cohort_ID` int(11) NOT NULL COMMENT '屆別ID',
  `year_label` varchar(20) NOT NULL COMMENT '學年(純數值/代號)',
  `cohort_name` varchar(30) NOT NULL COMMENT '顯示名稱',
  `cohort_start_d` date DEFAULT NULL COMMENT '該屆起始時間',
  `cohort_end_d` date DEFAULT NULL COMMENT '該屆結束時間',
  `cohort_status` int(11) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='學籍屆別表';

--
-- 傾印資料表的資料 `cohortdata`
--

INSERT INTO `cohortdata` (`cohort_ID`, `year_label`, `cohort_name`, `cohort_start_d`, `cohort_end_d`, `cohort_status`) VALUES
(1, '108', '108級', NULL, NULL, 0),
(2, '109', '109級', NULL, NULL, 0),
(3, '110', '110級', '2025-06-30', NULL, 1),
(4, '111', '111級', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- 資料表結構 `docdata`
--

CREATE TABLE `docdata` (
  `doc_ID` int(11) NOT NULL,
  `doc_name` varchar(150) NOT NULL COMMENT '名稱',
  `doc_des` text DEFAULT NULL COMMENT '說明',
  `doc_type` varchar(100) DEFAULT NULL COMMENT '副檔名清單',
  `doc_example` text DEFAULT NULL COMMENT '範例文件',
  `is_top` tinyint(1) DEFAULT NULL COMMENT '是否置頂',
  `is_required` int(11) DEFAULT NULL COMMENT '是否必要',
  `doc_start_d` datetime DEFAULT NULL COMMENT '開放時間',
  `doc_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `doc_status` int(11) NOT NULL COMMENT '狀態',
  `doc_u_ID` varchar(25) DEFAULT NULL COMMENT '創建者',
  `doc_created_d` datetime DEFAULT NULL COMMENT '建立時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文件';

-- --------------------------------------------------------

--
-- 資料表結構 `docsubdata`
--

CREATE TABLE `docsubdata` (
  `sub_ID` int(11) NOT NULL COMMENT '申請ID',
  `doc_ID` int(11) NOT NULL COMMENT '文件',
  `dcsub_team_ID` int(11) DEFAULT NULL COMMENT '團隊',
  `dcsub_u_ID` varchar(25) DEFAULT NULL COMMENT '上傳者',
  `dcsub_comment` text DEFAULT NULL COMMENT '說明文字',
  `dcsub_url` text DEFAULT NULL COMMENT '文件位置',
  `dcsub_sub_d` datetime NOT NULL DEFAULT current_timestamp() COMMENT '上傳時間',
  `dc_approved_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `dcsub_approved_d` datetime DEFAULT NULL COMMENT '審核時間',
  `dcsub_remark` text DEFAULT NULL COMMENT '審核備註',
  `dcsub_status` int(11) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文件繳交';

-- --------------------------------------------------------

--
-- 資料表結構 `doctargetdata`
--

CREATE TABLE `doctargetdata` (
  `doc_ID` int(11) NOT NULL COMMENT '文件ID',
  `doc_target_type` enum('ALL','COHORT','CLASS','TEAM','USER','GROUP') NOT NULL COMMENT '資料表',
  `doc_target_ID` varchar(50) NOT NULL COMMENT '目標ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文件目標';

-- --------------------------------------------------------

--
-- 資料表結構 `enrollmentdata`
--

CREATE TABLE `enrollmentdata` (
  `enroll_ID` int(11) NOT NULL,
  `enroll_u_ID` varchar(25) NOT NULL COMMENT '使用者',
  `cohort_ID` int(11) NOT NULL COMMENT '屆別ID',
  `class_ID` int(11) DEFAULT NULL COMMENT '該屆班級',
  `role_ID` int(11) DEFAULT NULL COMMENT '該屆角色',
  `enroll_grade` int(11) DEFAULT NULL COMMENT '該屆年級',
  `enroll_status` int(11) NOT NULL COMMENT '狀態',
  `enroll_created_d` datetime DEFAULT NULL COMMENT '建立時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='學籍屆別歷史紀錄表';

--
-- 傾印資料表的資料 `enrollmentdata`
--

INSERT INTO `enrollmentdata` (`enroll_ID`, `enroll_u_ID`, `cohort_ID`, `class_ID`, `role_ID`, `enroll_grade`, `enroll_status`, `enroll_created_d`) VALUES
(1, '109534201', 2, 2, 6, 5, 0, '2025-11-06 12:50:06'),
(2, '109534206', 2, 2, 6, 5, 0, '2025-11-06 12:50:06'),
(3, '109534207', 2, 2, 6, 5, 0, '2025-11-06 12:50:06'),
(4, '110511114', 3, 1, 6, 5, 1, '2025-11-06 12:50:06'),
(5, '110534101', 3, 1, 6, 5, 1, '2025-11-06 12:50:06'),
(6, '110534102', 3, 1, 6, 5, 1, '2025-11-06 12:50:06'),
(7, '110534201', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(8, '110534205', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(9, '110534206', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(10, '110534209', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(11, '110534210', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(12, '110534211', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(13, '110534212', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(14, '110534213', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(15, '110534215', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(16, '110534216', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(17, '110534217', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(18, '110534221', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(19, '110534224', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(20, '110534225', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(21, '110534231', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(22, '110534236', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(23, '110534244', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
(38, 'tengteng', 2, NULL, 4, NULL, 0, '2025-11-06 12:58:50'),
(39, 'tengteng', 3, NULL, 4, NULL, 1, '2025-11-06 12:58:50'),
(40, 'beckchou', 3, NULL, 4, NULL, 1, '2025-11-06 12:58:50'),
(41, 'toshiko', 3, NULL, 4, NULL, 1, '2025-11-06 12:58:50'),
(42, 'system', 3, NULL, 0, NULL, 1, '2025-11-06 12:58:50'),
(43, 'uknim', 3, NULL, 2, NULL, 1, '2025-11-06 12:58:50'),
(44, '109534201', 2, 2, NULL, 5, 1, '2025-11-13 10:21:43'),
(45, '109534206', 2, 2, NULL, 5, 1, '2025-11-13 10:21:43'),
(46, '109534207', 2, 2, NULL, 5, 1, '2025-11-13 10:21:43'),
(47, '110534202', 3, 2, NULL, 5, 1, '2025-11-13 10:21:43'),
(48, '110534207', 3, 2, NULL, 5, 1, '2025-11-13 10:21:43'),
(49, '110534235', 3, 2, NULL, 5, 1, '2025-11-13 10:21:43'),
(50, '110534242', 3, 2, NULL, 5, 1, '2025-11-13 10:21:43');

-- --------------------------------------------------------

--
-- 資料表結構 `filedata`
--

CREATE TABLE `filedata` (
  `file_ID` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_url` varchar(255) NOT NULL,
  `file_des` text DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 0,
  `file_start_d` datetime DEFAULT NULL,
  `file_end_d` datetime DEFAULT NULL,
  `file_status` tinyint(1) DEFAULT 1,
  `is_top` tinyint(1) DEFAULT 0,
  `file_update_d` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `groupdata`
--

CREATE TABLE `groupdata` (
  `group_ID` int(11) NOT NULL COMMENT '類組主鍵',
  `group_name` varchar(25) NOT NULL COMMENT '類組名稱',
  `group_status` int(11) NOT NULL COMMENT '狀態',
  `group_created_d` datetime DEFAULT NULL COMMENT '創建時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='分類';

--
-- 傾印資料表的資料 `groupdata`
--

INSERT INTO `groupdata` (`group_ID`, `group_name`, `group_status`, `group_created_d`) VALUES
(1, '系統組', 1, '2025-07-17 03:56:43'),
(2, '商務組', 1, '2025-07-17 03:56:43'),
(3, '測試組', 1, '2025-08-11 15:32:58'),
(4, 'ukn', 1, '2025-09-17 14:46:36');

-- --------------------------------------------------------

--
-- 資料表結構 `milesdata`
--

CREATE TABLE `milesdata` (
  `ms_ID` int(11) NOT NULL COMMENT '里程碑ID',
  `req_ID` int(11) DEFAULT NULL COMMENT '基本需求ID',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `ms_title` varchar(150) NOT NULL COMMENT '標題',
  `ms_desc` text DEFAULT NULL COMMENT '內容',
  `ms_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `ms_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `ms_u_ID` varchar(25) DEFAULT NULL COMMENT '完成者',
  `ms_url` text DEFAULT NULL COMMENT '檔案位置',
  `ms_completed_d` datetime DEFAULT NULL COMMENT '完成時間',
  `ms_approved_d` datetime DEFAULT NULL COMMENT '通過時間',
  `ms_approved_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `ms_status` int(11) NOT NULL COMMENT '狀態',
  `ms_priority` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=一般, 1=重要, 2=緊急, 3=超級緊急',
  `ms_created_d` datetime DEFAULT NULL COMMENT '建立人'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='里程碑';

-- --------------------------------------------------------

--
-- 資料表結構 `msgdata`
--

CREATE TABLE `msgdata` (
  `msg_ID` int(11) NOT NULL,
  `msg_title` text NOT NULL COMMENT '標題',
  `msg_content` text DEFAULT NULL COMMENT '內容',
  `msg_url` text DEFAULT NULL COMMENT '可放JSON陣列：圖片/URL/PDF等',
  `msg_a_u_ID` varchar(25) DEFAULT NULL COMMENT '創建人',
  `priority` int(11) DEFAULT NULL COMMENT '跑馬燈排序：越大越前',
  `msg_type` enum('ANNOUNCEMENT','SYSTEM_NOTICE','REMINDER') NOT NULL COMMENT '類型、區分用途',
  `msg_status` int(11) NOT NULL COMMENT '狀態',
  `msg_start_d` datetime DEFAULT NULL COMMENT '發布時間',
  `msg_end_d` datetime DEFAULT NULL COMMENT '結束時間',
  `msg_created_d` datetime DEFAULT NULL COMMENT '創建時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='公告、通知';

--
-- 傾印資料表的資料 `msgdata`
--

INSERT INTO `msgdata` (`msg_ID`, `msg_title`, `msg_content`, `msg_url`, `msg_a_u_ID`, `priority`, `msg_type`, `msg_status`, `msg_start_d`, `msg_end_d`, `msg_created_d`) VALUES
(1, '專題申請通知', '學生 尤斯婷 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 06:38:31', NULL, '2025-11-21 06:38:31'),
(2, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 10:47:03', NULL, '2025-11-21 10:47:03'),
(3, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 10:47:04', NULL, '2025-11-21 10:47:04'),
(4, '專題申請通知', '學生 達達 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 10:49:26', NULL, '2025-11-21 10:49:26'),
(5, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 11:15:52', NULL, '2025-11-21 11:15:52'),
(6, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 11:15:54', NULL, '2025-11-21 11:15:54'),
(7, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 11:20:08', NULL, '2025-11-21 11:20:08'),
(8, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 11:20:09', NULL, '2025-11-21 11:20:09'),
(9, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 11:25:56', NULL, '2025-11-21 11:25:56'),
(10, '專題申請通知', '學生 馬馬咩 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 11:28:44', NULL, '2025-11-21 11:28:44'),
(11, '專題申請通知', '學生 下巴宏 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-11-21 13:34:50', NULL, '2025-11-21 13:34:50');

-- --------------------------------------------------------

--
-- 資料表結構 `msgreaddata`
--

CREATE TABLE `msgreaddata` (
  `msg_ID` int(11) NOT NULL COMMENT '訊息',
  `read_u_ID` varchar(25) NOT NULL COMMENT '讀取人',
  `msg_read_d` datetime DEFAULT NULL COMMENT '讀取時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='讀取紀錄';

--
-- 傾印資料表的資料 `msgreaddata`
--

INSERT INTO `msgreaddata` (`msg_ID`, `read_u_ID`, `msg_read_d`) VALUES
(1, 'uknim', '2025-11-21 06:44:44'),
(2, 'uknim', '2025-11-21 13:36:58'),
(3, 'uknim', '2025-11-21 13:36:57'),
(4, 'uknim', '2025-11-21 13:36:57'),
(5, 'uknim', '2025-11-21 13:36:56'),
(6, 'uknim', '2025-11-21 13:36:55'),
(7, 'uknim', '2025-11-21 13:36:54'),
(8, 'uknim', '2025-11-21 13:36:53'),
(9, 'uknim', '2025-11-21 13:36:53'),
(10, 'uknim', '2025-11-21 13:36:52'),
(11, 'uknim', '2025-11-21 13:36:52');

-- --------------------------------------------------------

--
-- 資料表結構 `msgtargetdata`
--

CREATE TABLE `msgtargetdata` (
  `msg_ID` int(11) NOT NULL COMMENT '訊息',
  `msg_target_type` enum('ALL','COHORT','CLASS','TEAM','USER') NOT NULL COMMENT '目標對象',
  `msg_target_ID` varchar(50) NOT NULL COMMENT '對象ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='訊息、公告目標對象';

--
-- 傾印資料表的資料 `msgtargetdata`
--

INSERT INTO `msgtargetdata` (`msg_ID`, `msg_target_type`, `msg_target_ID`) VALUES
(1, 'USER', 'uknim'),
(2, 'USER', 'uknim'),
(3, 'USER', 'uknim'),
(4, 'USER', 'uknim'),
(5, 'USER', 'uknim'),
(6, 'USER', 'uknim'),
(7, 'USER', 'uknim'),
(8, 'USER', 'uknim'),
(9, 'USER', 'uknim'),
(10, 'USER', 'uknim'),
(11, 'USER', 'uknim');

-- --------------------------------------------------------

--
-- 資料表結構 `pereviewdata`
--

CREATE TABLE `pereviewdata` (
  `peer_ID` int(11) NOT NULL COMMENT '互評紀錄ID',
  `period_ID` int(11) NOT NULL COMMENT '評分ID',
  `pe_target_ID` int(11) NOT NULL COMMENT '目標ID（petargetdata）',
  `pe_u_ID` varchar(25) NOT NULL COMMENT '評分者ID',
  `score` int(11) DEFAULT NULL COMMENT '星等評分',
  `peer_comment` text DEFAULT NULL COMMENT '評論',
  `created_d` datetime DEFAULT NULL COMMENT '評論時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='存放互評紀錄';

-- --------------------------------------------------------

--
-- 資料表結構 `perioddata`
--

CREATE TABLE `perioddata` (
  `period_ID` int(11) NOT NULL COMMENT '流水號',
  `period_title` varchar(100) NOT NULL COMMENT '標題',
  `period_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `period_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `pe_created_d` datetime DEFAULT NULL COMMENT '建立時間',
  `pe_created_u_ID` varchar(25) NOT NULL COMMENT '建立者'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='存放設立評分資料';

-- --------------------------------------------------------

--
-- 資料表結構 `petargetdata`
--

CREATE TABLE `petargetdata` (
  `pe_target_ID` int(11) NOT NULL COMMENT '流水號',
  `period_ID` int(11) NOT NULL COMMENT '所屬評分時段',
  `pe_team_ID` int(11) DEFAULT NULL COMMENT '被評分團隊',
  `pe_class_ID` int(11) DEFAULT NULL COMMENT '班級ID',
  `pe_cohort_ID` int(11) DEFAULT NULL COMMENT '屆',
  `pe_grade_no` int(11) DEFAULT NULL COMMENT '年級',
  `status_ID` int(11) DEFAULT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='存放互評目標（多對一：period→targets）';

-- --------------------------------------------------------

--
-- 資料表結構 `projectdata`
--

CREATE TABLE `projectdata` (
  `pro_ID` int(11) NOT NULL COMMENT '專題ID',
  `pro_chorot_ID` int(11) NOT NULL COMMENT '屆ID',
  `pro_title` text NOT NULL COMMENT '標題',
  `pro_des` text DEFAULT NULL COMMENT '內容',
  `pro_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `pro_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `pro_type` varchar(200) DEFAULT NULL COMMENT '文件格式',
  `pro_example` text DEFAULT NULL COMMENT '範例文件',
  `pro_status` int(11) NOT NULL COMMENT '狀態',
  `pro_created_u_ID` varchar(25) DEFAULT NULL COMMENT '創建者',
  `pro_created_d` datetime DEFAULT NULL COMMENT '創建時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='歷屆專題';

-- --------------------------------------------------------

--
-- 資料表結構 `prosubdata`
--

CREATE TABLE `prosubdata` (
  `prosub_ID` int(11) NOT NULL COMMENT '流水號',
  `pro_ID` int(11) NOT NULL COMMENT '專題資料ID',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `prosub_img` text DEFAULT NULL COMMENT '海報',
  `prosub_other` text DEFAULT NULL COMMENT '多個檔案',
  `content_json` text DEFAULT NULL COMMENT '備用JSON欄位',
  `prosub_u_ID` varchar(25) DEFAULT NULL COMMENT '繳交人',
  `prosub_created_d` datetime DEFAULT NULL COMMENT '繳交時間',
  `prosub_reason` text DEFAULT NULL COMMENT '申請修改原因',
  `prosub_re_reason` text DEFAULT NULL COMMENT '審核備註',
  `prosub_re_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `prosub_re_d` datetime DEFAULT NULL COMMENT '審核時間',
  `prosub_status` int(11) NOT NULL COMMENT '狀態',
  `prosub_update_d` datetime DEFAULT NULL COMMENT '更新時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='歷屆專題繳交資料';

-- --------------------------------------------------------

--
-- 資料表結構 `reprogressdata`
--

CREATE TABLE `reprogressdata` (
  `rp_ID` int(11) NOT NULL COMMENT '紀錄ID',
  `req_ID` int(11) NOT NULL COMMENT '基本需求',
  `rp_team_ID` int(11) DEFAULT NULL COMMENT '團隊',
  `rp_u_ID` varchar(25) DEFAULT NULL COMMENT '完成者',
  `rp_status` int(11) NOT NULL COMMENT '狀態',
  `rp_completed_d` datetime DEFAULT NULL COMMENT '完成時間',
  `rp_approved_d` datetime DEFAULT NULL COMMENT '審核時間',
  `rp_approved_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `rp_remark` text DEFAULT NULL COMMENT '說明'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='基本需求紀錄';

-- --------------------------------------------------------

--
-- 資料表結構 `requirementdata`
--

CREATE TABLE `requirementdata` (
  `req_ID` int(11) NOT NULL COMMENT '需求ID',
  `cohort_ID` int(11) DEFAULT NULL COMMENT '屆別ID',
  `group_ID` int(11) DEFAULT NULL COMMENT '類組ID',
  `type_ID` int(11) DEFAULT NULL COMMENT '分類',
  `req_title` varchar(300) NOT NULL COMMENT '需求標題',
  `req_direction` text DEFAULT NULL COMMENT '需求說明',
  `req_count` text DEFAULT NULL COMMENT '需求量化',
  `req_u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `color_hex` char(7) DEFAULT NULL COMMENT '顏色(甘特圖顯示)',
  `req_status` int(11) NOT NULL COMMENT '狀態',
  `req_created_d` datetime DEFAULT NULL COMMENT '創建時間',
  `edit_u_ID` varchar(25) DEFAULT NULL COMMENT '最後編輯者帳號',
  `req_update_d` datetime NOT NULL COMMENT '最後編輯時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='基本需求';

-- --------------------------------------------------------

--
-- 資料表結構 `roledata`
--

CREATE TABLE `roledata` (
  `role_ID` int(11) NOT NULL COMMENT '角色',
  `role_name` varchar(25) NOT NULL COMMENT '角色名稱',
  `role_direction` text DEFAULT NULL COMMENT '角色說明',
  `role_created_d` datetime NOT NULL COMMENT '創建時間',
  `role_status` int(11) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='使用角色';

--
-- 傾印資料表的資料 `roledata`
--

INSERT INTO `roledata` (`role_ID`, `role_name`, `role_direction`, `role_created_d`, `role_status`) VALUES
(0, '系統', '系統', '2025-11-06 11:30:41', 1),
(1, '主任', '主任', '2025-11-06 11:30:41', 1),
(2, '科辦', '科辦', '2025-11-06 11:30:41', 1),
(3, '班導', '班導', '2025-11-06 11:30:41', 1),
(4, '指導老師', '指導老師', '2025-11-06 11:30:41', 1),
(5, '訪客', '訪客', '2025-11-06 11:30:41', 1),
(6, '學生', '學生', '2025-11-06 11:30:41', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `statusdata`
--

CREATE TABLE `statusdata` (
  `status_ID` int(11) NOT NULL COMMENT '狀態ID',
  `status_name` char(15) NOT NULL COMMENT '狀態名稱'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='狀態';

--
-- 傾印資料表的資料 `statusdata`
--

INSERT INTO `statusdata` (`status_ID`, `status_name`) VALUES
(0, '停用'),
(1, '正常'),
(2, '異常'),
(3, '已結案'),
(4, '暫存');

-- --------------------------------------------------------

--
-- 資料表結構 `suggest`
--

CREATE TABLE `suggest` (
  `suggest_ID` int(11) NOT NULL COMMENT '主鍵',
  `suggest_u_ID` varchar(25) NOT NULL COMMENT '評論者',
  `team_ID` int(11) NOT NULL COMMENT '被評論團隊',
  `type_ID` int(11) DEFAULT NULL COMMENT '分類ID',
  `suggest_name` varchar(100) DEFAULT NULL COMMENT '標題',
  `suggest_comment` text DEFAULT NULL COMMENT '評論內容',
  `suggest_d` datetime DEFAULT NULL COMMENT '評論時間',
  `suggest_status` int(11) DEFAULT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='期中期末建議';

--
-- 傾印資料表的資料 `suggest`
--

INSERT INTO `suggest` (`suggest_ID`, `suggest_u_ID`, `team_ID`, `type_ID`, `suggest_name`, `suggest_comment`, `suggest_d`, `suggest_status`) VALUES
(2, 'toshiko', 2, 1, '110級系統組期中建議', '1. saf', '2025-11-21 04:58:50', 1),
(3, 'toshiko', 4, 1, '110級系統組期中建議', '1. asdfas\n2. fas', '2025-11-21 04:58:51', 1),
(4, 'uknim', 1, 1, '110級系統組期中建議', '1. dsjfl', '2025-11-21 14:19:51', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `taskdata`
--

CREATE TABLE `taskdata` (
  `task_ID` int(11) NOT NULL COMMENT '任務ID',
  `task_team_ID` int(11) DEFAULT NULL COMMENT '團隊ID',
  `task_u_ID` varchar(25) DEFAULT NULL COMMENT '創立者',
  `task_cohort_ID` int(11) DEFAULT NULL COMMENT '屆別ID',
  `ms_ID` int(11) DEFAULT NULL COMMENT '里程碑ID',
  `req_ID` int(11) DEFAULT NULL COMMENT '基本需求ID',
  `task_title` varchar(150) NOT NULL COMMENT '標題',
  `task_desc` text DEFAULT NULL COMMENT '內容',
  `task_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `task_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `task_done_u_ID` varchar(25) DEFAULT NULL COMMENT '完成人',
  `task_done_d` datetime DEFAULT NULL COMMENT '完成時間',
  `task_status` int(11) NOT NULL COMMENT '狀態',
  `task_priority` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=一般, 1=重要, 2=緊急, 3=超級緊急',
  `task_created_d` datetime DEFAULT NULL COMMENT '建立時間',
  `task_url` text DEFAULT NULL COMMENT '檔案位置'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='任務';

-- --------------------------------------------------------

--
-- 資料表結構 `teamapply`
--

CREATE TABLE `teamapply` (
  `tap_ID` int(11) NOT NULL COMMENT '流水號',
  `tap_name` varchar(20) NOT NULL COMMENT '團隊名稱',
  `tap_member` text DEFAULT NULL COMMENT '團隊成員(JSON字串)',
  `tap_teacher` varchar(25) NOT NULL COMMENT '指導老師',
  `tap_url` text DEFAULT NULL COMMENT '提交檔案',
  `tap_des` text DEFAULT NULL COMMENT '說明文字',
  `tap_status` int(11) NOT NULL COMMENT '狀態',
  `tap_u_ID` varchar(25) NOT NULL COMMENT '提交者',
  `tap_rp_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人(關聯u_ID)',
  `tap_rp_d` datetime DEFAULT NULL COMMENT '審核時間',
  `tap_update_d` datetime DEFAULT NULL COMMENT '更新時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `teamapply`
--

INSERT INTO `teamapply` (`tap_ID`, `tap_name`, `tap_member`, `tap_teacher`, `tap_url`, `tap_des`, `tap_status`, `tap_u_ID`, `tap_rp_u_ID`, `tap_rp_d`, `tap_update_d`) VALUES
(2, '321', '[\"110534206\",\"110534244\"]', 'tengteng', 'uploads/team_apply/apply_110534244_1763695724.jpg', '', 1, '110534244', NULL, NULL, '2025-11-21 11:28:44'),
(3, '123', '[\"110534210\",\"110534212\"]', 'tengteng', 'uploads/team_apply/apply_110534212_1763703290.jpg', '', 1, '110534212', NULL, NULL, '2025-11-21 13:34:50');

-- --------------------------------------------------------

--
-- 資料表結構 `teamdata`
--

CREATE TABLE `teamdata` (
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `group_ID` int(11) DEFAULT NULL COMMENT '類組',
  `team_project_name` varchar(25) DEFAULT NULL COMMENT '專題名稱',
  `cohort_ID` int(11) DEFAULT NULL COMMENT '屆別',
  `team_status` int(11) NOT NULL COMMENT '狀態',
  `team_update_d` datetime DEFAULT NULL COMMENT '更新時間',
  `team_url` text NOT NULL COMMENT '申請檔案'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='團隊';

--
-- 傾印資料表的資料 `teamdata`
--

INSERT INTO `teamdata` (`team_ID`, `group_ID`, `team_project_name`, `cohort_ID`, `team_status`, `team_update_d`, `team_url`) VALUES
(1, 1, '專題管理', 3, 1, '2025-11-06 12:14:02', ''),
(2, 1, '微旅日記', 3, 1, '2025-11-06 12:14:02', ''),
(3, 1, '昊德經絡', 2, 0, '2025-11-06 12:14:02', ''),
(4, 1, '產學合作', 3, 1, '2025-11-06 12:14:02', ''),
(5, 2, '逮妮娜組', 3, 1, '2025-11-06 12:14:02', ''),
(6, 3, '測試', 3, 1, '2025-11-06 12:14:02', '');

-- --------------------------------------------------------

--
-- 資料表結構 `teammember`
--

CREATE TABLE `teammember` (
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `team_u_ID` varchar(25) NOT NULL COMMENT '使用者',
  `tm_status` int(11) DEFAULT NULL COMMENT '狀態',
  `tm_updated_d` datetime DEFAULT NULL COMMENT '更新時間',
  `tm_url` text DEFAULT NULL COMMENT '異動檔案提交'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='團隊成員';

--
-- 傾印資料表的資料 `teammember`
--

INSERT INTO `teammember` (`team_ID`, `team_u_ID`, `tm_status`, `tm_updated_d`, `tm_url`) VALUES
(1, '110534205', 1, '2025-11-06 12:17:00', NULL),
(1, '110534215', 1, '2025-11-06 12:17:00', NULL),
(1, '110534221', 1, '2025-11-06 12:17:00', NULL),
(1, '110534231', 1, '2025-11-06 12:17:00', NULL),
(1, 'tengteng', 1, '2025-11-06 12:17:00', NULL),
(2, '110534207', 1, '2025-11-06 12:17:00', NULL),
(2, '110534216', 1, '2025-11-06 12:17:00', NULL),
(2, '110534217', 1, '2025-11-06 12:17:00', NULL),
(2, 'toshiko', 1, '2025-11-06 12:17:00', NULL),
(3, '109534201', 1, '2025-11-06 12:17:00', NULL),
(3, '109534206', 1, '2025-11-06 12:17:00', NULL),
(3, '109534207', 1, '2025-11-06 12:17:00', NULL),
(3, 'tengteng', 1, '2025-11-06 12:17:00', NULL),
(4, '110511114', 1, '2025-11-06 12:17:00', NULL),
(4, '110534201', 1, '2025-11-06 12:17:00', NULL),
(4, '110534225', 1, '2025-11-06 12:17:00', NULL),
(4, '110534236', 1, '2025-11-06 12:17:00', NULL),
(4, 'tengteng', 1, '2025-11-06 12:17:00', NULL),
(5, '110534101', 1, '2025-11-06 12:17:00', NULL),
(5, '110534102', 1, '2025-11-06 12:17:00', NULL),
(5, '110534202', 1, '2025-11-06 12:17:00', NULL),
(5, '110534213', 1, '2025-11-06 12:17:00', NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `timedata`
--

CREATE TABLE `timedata` (
  `time_ID` int(11) NOT NULL COMMENT '流水號',
  `tinforma_ID` int(11) NOT NULL COMMENT '資訊ID（對應 timeinformadata）',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `time_name` text NOT NULL COMMENT '標題',
  `type_ID` int(11) DEFAULT NULL COMMENT '分類ID',
  `time_start_d` datetime NOT NULL COMMENT '開始時間',
  `time_end_d` datetime NOT NULL COMMENT '結束時間',
  `sort_no` int(11) DEFAULT NULL COMMENT '手動排序(組次)，可空；越小越前'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='時程表目標t';

-- --------------------------------------------------------

--
-- 資料表結構 `timeinformadata`
--

CREATE TABLE `timeinformadata` (
  `tinforma_ID` int(11) NOT NULL COMMENT '流水號',
  `tinforma_content` text NOT NULL COMMENT '包含場次準備、上台報告說明、午餐時間、中場休息',
  `tinforma_create_d` datetime NOT NULL DEFAULT current_timestamp() COMMENT '建立時間',
  `tinforma_update_d` datetime DEFAULT NULL ON UPDATE current_timestamp() COMMENT '最後更新時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='時程表資訊';

-- --------------------------------------------------------

--
-- 資料表結構 `typedata`
--

CREATE TABLE `typedata` (
  `type_ID` int(11) NOT NULL COMMENT '主鍵',
  `type_value` varchar(50) NOT NULL COMMENT '名稱（例：期中、期末、一般、公告、通知）',
  `type_status` int(11) NOT NULL COMMENT '狀態',
  `type_created_d` datetime NOT NULL DEFAULT current_timestamp() COMMENT '創建時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='分類';

--
-- 傾印資料表的資料 `typedata`
--

INSERT INTO `typedata` (`type_ID`, `type_value`, `type_status`, `type_created_d`) VALUES
(1, '期中', 1, '2025-11-21 04:47:43');

-- --------------------------------------------------------

--
-- 資料表結構 `userdata`
--

CREATE TABLE `userdata` (
  `u_ID` varchar(25) NOT NULL COMMENT '使用者帳號',
  `u_password` char(20) NOT NULL COMMENT '密碼(請改雜湊儲存)',
  `u_name` char(10) NOT NULL COMMENT '中文姓名',
  `u_gmail` varchar(150) NOT NULL COMMENT '信箱',
  `u_profile` varchar(300) DEFAULT NULL COMMENT '個人檔案',
  `u_img` text DEFAULT NULL COMMENT '頭貼路徑/URL',
  `u_status` int(11) NOT NULL COMMENT '狀態',
  `u_update_d` datetime DEFAULT NULL COMMENT '最後修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='使用者';

--
-- 傾印資料表的資料 `userdata`
--

INSERT INTO `userdata` (`u_ID`, `u_password`, `u_name`, `u_gmail`, `u_profile`, `u_img`, `u_status`, `u_update_d`) VALUES
('109534201', '109534201', '林恩宇', '109534201@stu.ukn.edu.tw', '我站在雲林', 'u_img_109534201_1763675445.jpg', 3, '2025-11-06 11:28:35'),
('109534206', '109534206', '蓁蓁咪', '109534206@stu.ukn.edu.tw', '早上沒事，晚上台中市', 'u_img_109534206_1755065596.png', 3, '2025-11-06 11:28:35'),
('109534207', '109534207', '書桑', '109534207@stu.ukn.edu.tw', '', NULL, 3, '2025-11-06 11:28:35'),
('110511114', '110511114', '王加桑', '110511114@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534101', '110534101', '忠班1', '110534101@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534102', '110534102', '忠班2', '110534102@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534201', '110534201', '方方土', '110534201@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534202', '110534202', '逢尼', '110534202@stu.ukn.edu.tw', '', NULL, 1, '2025-11-06 11:28:35'),
('110534205', '110534205', '邱桑', '110534205@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534206', '110534206', '尤斯婷', '110534206@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534207', '110534207', '沁桑', '110534207@stu.ukn.edu.tw', '', NULL, 1, '2025-11-06 11:28:35'),
('110534209', '110534209', '一聰', '110534209@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534210', '110534210', '俊成', '110534210@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534211', '110534211', '思維', '110534211@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534212', '110534212', '下巴宏', '110534212@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534213', '110534213', '登桑', '110534213@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534215', '110534215', '莉桑', '110534215@stu.ukn.edu.tw', '嗨莉莉莉', 'u_img_110534215_1763675534.jpg', 1, '2025-11-06 11:28:35'),
('110534216', '110534216', '玲桑', '110534216@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534217', '110534217', '小恩恩桑', '110534217@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534221', '110534221', '羅桑', '110534221@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534224', '110534224', '達達', '110534224@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534225', '110534225', '由桑', '110534225@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534226', '110534226', '圻恩桑', '110534226@stu.ukn.edu.tw', '竟佔', NULL, 1, NULL),
('110534231', '110534231', '凱桑', '110534231@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534235', '110534235', '加一', '110534235@stu.ukn.edu.tw', '', NULL, 1, '2025-11-06 11:28:35'),
('110534236', '110534236', '二廷', '110534236@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534242', '110534242', '宏陳', '110534242@stu.ukn.edu.tw', '', NULL, 1, '2025-11-06 11:28:35'),
('110534244', '110534244', '馬馬咩', '110534244@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('beckchou', '5678', '建宇貝殼', 'beckchou@g.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('system', 'uknsystempro', '系統', '', NULL, NULL, 1, '2025-11-06 11:28:35'),
('tengteng', '1234', '宗騰騰', 'ttchen@ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('toshiko', '1234', '竹華老師', '', NULL, NULL, 1, '2025-11-06 11:28:35'),
('uknim', '1234', '科辦', '', NULL, NULL, 1, '2025-11-06 11:28:35');

-- --------------------------------------------------------

--
-- 資料表結構 `userrolesdata`
--

CREATE TABLE `userrolesdata` (
  `ur_u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `role_ID` int(11) NOT NULL COMMENT '角色ID',
  `user_role_status` int(11) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='使用者角色關聯';

--
-- 傾印資料表的資料 `userrolesdata`
--

INSERT INTO `userrolesdata` (`ur_u_ID`, `role_ID`, `user_role_status`) VALUES
('109534201', 5, 0),
('109534201', 6, 1),
('109534206', 6, 1),
('109534207', 6, 1),
('110511114', 6, 1),
('110534101', 6, 1),
('110534102', 6, 1),
('110534201', 6, 1),
('110534202', 6, 1),
('110534205', 6, 1),
('110534206', 6, 1),
('110534207', 6, 1),
('110534209', 6, 1),
('110534210', 6, 1),
('110534211', 6, 1),
('110534212', 6, 1),
('110534213', 6, 1),
('110534215', 6, 1),
('110534216', 6, 1),
('110534217', 6, 1),
('110534221', 6, 1),
('110534224', 6, 1),
('110534225', 6, 1),
('110534226', 6, 1),
('110534231', 6, 1),
('110534235', 6, 1),
('110534236', 6, 1),
('110534242', 6, 1),
('110534244', 6, 1),
('beckchou', 3, 1),
('beckchou', 4, 1),
('system', 0, 1),
('tengteng', 4, 1),
('toshiko', 1, 1),
('toshiko', 3, 1),
('toshiko', 4, 1),
('uknim', 2, 1);

-- --------------------------------------------------------

--
-- 資料表結構 `workdata`
--

CREATE TABLE `workdata` (
  `work_ID` int(11) NOT NULL,
  `work_title` text NOT NULL COMMENT '標題',
  `work_content` text DEFAULT NULL COMMENT '內容',
  `work_u_ID` varchar(25) NOT NULL COMMENT '提交者',
  `req_ID` int(11) DEFAULT NULL,
  `ms_ID` int(11) DEFAULT NULL,
  `task_ID` int(11) DEFAULT NULL,
  `work_status` int(11) NOT NULL COMMENT '狀態',
  `comment` text DEFAULT NULL COMMENT '團隊其他人留言',
  `work_update_d` datetime DEFAULT NULL COMMENT '修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='工作日誌';

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `accesslogs`
--
ALTER TABLE `accesslogs`
  ADD PRIMARY KEY (`access_ID`),
  ADD KEY `fk_ac_role` (`role_ID`),
  ADD KEY `idx_access_user_time` (`u_ID`,`access_time`);

--
-- 資料表索引 `actionlogs`
--
ALTER TABLE `actionlogs`
  ADD PRIMARY KEY (`action_ID`),
  ADD KEY `fk_al_user` (`u_ID`),
  ADD KEY `fk_al_role` (`role_ID`),
  ADD KEY `idx_action_time` (`action_time`);

--
-- 資料表索引 `classdata`
--
ALTER TABLE `classdata`
  ADD PRIMARY KEY (`c_ID`);

--
-- 資料表索引 `cohortdata`
--
ALTER TABLE `cohortdata`
  ADD PRIMARY KEY (`cohort_ID`),
  ADD KEY `fk_cohort_status` (`cohort_status`);

--
-- 資料表索引 `docdata`
--
ALTER TABLE `docdata`
  ADD PRIMARY KEY (`doc_ID`),
  ADD KEY `fk_doc_status` (`doc_status`),
  ADD KEY `fk_doc_user` (`doc_u_ID`);

--
-- 資料表索引 `docsubdata`
--
ALTER TABLE `docsubdata`
  ADD PRIMARY KEY (`sub_ID`),
  ADD KEY `fk_dcs_user` (`dcsub_u_ID`),
  ADD KEY `fk_dcs_appr` (`dc_approved_u_ID`),
  ADD KEY `fk_dcs_status` (`dcsub_status`),
  ADD KEY `idx_dcs_doc` (`doc_ID`),
  ADD KEY `idx_dcs_team` (`dcsub_team_ID`);

--
-- 資料表索引 `doctargetdata`
--
ALTER TABLE `doctargetdata`
  ADD PRIMARY KEY (`doc_ID`,`doc_target_type`,`doc_target_ID`);

--
-- 資料表索引 `enrollmentdata`
--
ALTER TABLE `enrollmentdata`
  ADD PRIMARY KEY (`enroll_ID`),
  ADD KEY `fk_enroll_class` (`class_ID`),
  ADD KEY `fk_enroll_role` (`role_ID`),
  ADD KEY `fk_enroll_status` (`enroll_status`),
  ADD KEY `idx_enroll_user` (`enroll_u_ID`),
  ADD KEY `idx_enroll_cohort` (`cohort_ID`);

--
-- 資料表索引 `filedata`
--
ALTER TABLE `filedata`
  ADD PRIMARY KEY (`file_ID`);

--
-- 資料表索引 `groupdata`
--
ALTER TABLE `groupdata`
  ADD PRIMARY KEY (`group_ID`),
  ADD KEY `fk_group_status` (`group_status`);

--
-- 資料表索引 `milesdata`
--
ALTER TABLE `milesdata`
  ADD PRIMARY KEY (`ms_ID`),
  ADD KEY `fk_ms_user` (`ms_u_ID`),
  ADD KEY `fk_ms_status` (`ms_status`),
  ADD KEY `fk_ms_apprusr` (`ms_approved_u_ID`),
  ADD KEY `idx_ms_req` (`req_ID`),
  ADD KEY `idx_ms_team` (`team_ID`);

--
-- 資料表索引 `msgdata`
--
ALTER TABLE `msgdata`
  ADD PRIMARY KEY (`msg_ID`),
  ADD KEY `fk_msg_user` (`msg_a_u_ID`),
  ADD KEY `fk_msg_status` (`msg_status`),
  ADD KEY `idx_msg_time` (`msg_start_d`,`msg_end_d`),
  ADD KEY `idx_msg_type` (`msg_type`);

--
-- 資料表索引 `msgreaddata`
--
ALTER TABLE `msgreaddata`
  ADD PRIMARY KEY (`msg_ID`,`read_u_ID`),
  ADD KEY `fk_msgr_user` (`read_u_ID`);

--
-- 資料表索引 `msgtargetdata`
--
ALTER TABLE `msgtargetdata`
  ADD PRIMARY KEY (`msg_ID`,`msg_target_type`,`msg_target_ID`);

--
-- 資料表索引 `pereviewdata`
--
ALTER TABLE `pereviewdata`
  ADD PRIMARY KEY (`peer_ID`),
  ADD KEY `idx_prv_period` (`period_ID`),
  ADD KEY `idx_prv_target` (`pe_target_ID`),
  ADD KEY `idx_prv_user` (`pe_u_ID`);

--
-- 資料表索引 `perioddata`
--
ALTER TABLE `perioddata`
  ADD PRIMARY KEY (`period_ID`),
  ADD KEY `fk_pe_user` (`pe_created_u_ID`),
  ADD KEY `idx_period_time` (`period_start_d`,`period_end_d`);

--
-- 資料表索引 `petargetdata`
--
ALTER TABLE `petargetdata`
  ADD PRIMARY KEY (`pe_target_ID`),
  ADD KEY `idx_pet_period` (`period_ID`),
  ADD KEY `idx_pet_team` (`pe_team_ID`),
  ADD KEY `idx_pet_class` (`pe_class_ID`),
  ADD KEY `idx_pet_cohort` (`pe_cohort_ID`),
  ADD KEY `fk_petarget_status` (`status_ID`);

--
-- 資料表索引 `projectdata`
--
ALTER TABLE `projectdata`
  ADD PRIMARY KEY (`pro_ID`),
  ADD KEY `fk_pro_status` (`pro_status`),
  ADD KEY `fk_pro_user` (`pro_created_u_ID`),
  ADD KEY `idx_pro_cohort` (`pro_chorot_ID`);

--
-- 資料表索引 `prosubdata`
--
ALTER TABLE `prosubdata`
  ADD PRIMARY KEY (`prosub_ID`),
  ADD UNIQUE KEY `uk_project_team` (`pro_ID`,`team_ID`),
  ADD KEY `fk_psd_team` (`team_ID`),
  ADD KEY `fk_psd_user1` (`prosub_u_ID`),
  ADD KEY `fk_psd_user2` (`prosub_re_u_ID`),
  ADD KEY `fk_psd_status` (`prosub_status`);

--
-- 資料表索引 `reprogressdata`
--
ALTER TABLE `reprogressdata`
  ADD PRIMARY KEY (`rp_ID`),
  ADD KEY `fk_rp_team` (`rp_team_ID`),
  ADD KEY `fk_rp_user` (`rp_u_ID`),
  ADD KEY `fk_rp_status` (`rp_status`),
  ADD KEY `fk_rp_apprusr` (`rp_approved_u_ID`),
  ADD KEY `idx_rp_req` (`req_ID`);

--
-- 資料表索引 `requirementdata`
--
ALTER TABLE `requirementdata`
  ADD PRIMARY KEY (`req_ID`),
  ADD KEY `fk_req_group` (`group_ID`),
  ADD KEY `fk_req_user` (`req_u_ID`),
  ADD KEY `fk_req_status` (`req_status`),
  ADD KEY `fk_req_type` (`type_ID`),
  ADD KEY `idx_req_cohort` (`cohort_ID`),
  ADD KEY `fk_req_edit_user` (`edit_u_ID`);

--
-- 資料表索引 `roledata`
--
ALTER TABLE `roledata`
  ADD PRIMARY KEY (`role_ID`),
  ADD KEY `fk_role_status` (`role_status`);

--
-- 資料表索引 `statusdata`
--
ALTER TABLE `statusdata`
  ADD PRIMARY KEY (`status_ID`);

--
-- 資料表索引 `suggest`
--
ALTER TABLE `suggest`
  ADD PRIMARY KEY (`suggest_ID`),
  ADD KEY `fk_sug_user` (`suggest_u_ID`),
  ADD KEY `fk_sug_status` (`suggest_status`),
  ADD KEY `idx_sug_team` (`team_ID`),
  ADD KEY `fk_suggest_type` (`type_ID`);

--
-- 資料表索引 `taskdata`
--
ALTER TABLE `taskdata`
  ADD PRIMARY KEY (`task_ID`),
  ADD KEY `fk_task_user1` (`task_u_ID`),
  ADD KEY `fk_task_user2` (`task_done_u_ID`),
  ADD KEY `fk_task_status` (`task_status`),
  ADD KEY `idx_task_team` (`task_team_ID`),
  ADD KEY `idx_task_cohort` (`task_cohort_ID`),
  ADD KEY `fk_task_milestone` (`ms_ID`),
  ADD KEY `fk_task_requirement` (`req_ID`);

--
-- 資料表索引 `teamapply`
--
ALTER TABLE `teamapply`
  ADD PRIMARY KEY (`tap_ID`),
  ADD KEY `fk_teamapply_teacher_idx` (`tap_teacher`),
  ADD KEY `fk_teamapply_user_idx` (`tap_u_ID`),
  ADD KEY `fk_teamapply_status_idx` (`tap_status`),
  ADD KEY `fk_teamapply_reviewer_idx` (`tap_rp_u_ID`);

--
-- 資料表索引 `teamdata`
--
ALTER TABLE `teamdata`
  ADD PRIMARY KEY (`team_ID`),
  ADD KEY `fk_team_status` (`team_status`),
  ADD KEY `idx_team_group` (`group_ID`),
  ADD KEY `idx_team_cohort` (`cohort_ID`);

--
-- 資料表索引 `teammember`
--
ALTER TABLE `teammember`
  ADD PRIMARY KEY (`team_ID`,`team_u_ID`),
  ADD KEY `fk_tm_user` (`team_u_ID`),
  ADD KEY `fk_tm_status` (`tm_status`);

--
-- 資料表索引 `timedata`
--
ALTER TABLE `timedata`
  ADD PRIMARY KEY (`time_ID`),
  ADD UNIQUE KEY `uk_tinforma_team` (`tinforma_ID`,`team_ID`),
  ADD KEY `fk_time_team` (`team_ID`),
  ADD KEY `idx_timedata_sort` (`tinforma_ID`,`sort_no`),
  ADD KEY `fk_timedata_type` (`type_ID`);

--
-- 資料表索引 `timeinformadata`
--
ALTER TABLE `timeinformadata`
  ADD PRIMARY KEY (`tinforma_ID`);

--
-- 資料表索引 `typedata`
--
ALTER TABLE `typedata`
  ADD PRIMARY KEY (`type_ID`),
  ADD UNIQUE KEY `uk_type_value` (`type_value`),
  ADD KEY `fk_type_status` (`type_status`);

--
-- 資料表索引 `userdata`
--
ALTER TABLE `userdata`
  ADD PRIMARY KEY (`u_ID`),
  ADD KEY `fk_user_status` (`u_status`),
  ADD KEY `idx_user_mail` (`u_gmail`);

--
-- 資料表索引 `userrolesdata`
--
ALTER TABLE `userrolesdata`
  ADD PRIMARY KEY (`ur_u_ID`,`role_ID`),
  ADD KEY `fk_ur_role` (`role_ID`),
  ADD KEY `fk_ur_status` (`user_role_status`);

--
-- 資料表索引 `workdata`
--
ALTER TABLE `workdata`
  ADD PRIMARY KEY (`work_ID`),
  ADD KEY `fk_work_status` (`work_status`),
  ADD KEY `idx_work_user` (`work_u_ID`),
  ADD KEY `fk_work_req` (`req_ID`),
  ADD KEY `fk_work_ms` (`ms_ID`),
  ADD KEY `fk_work_task` (`task_ID`);

--
-- 在傾印的資料表使用自動遞增(AUTO_INCREMENT)
--

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `accesslogs`
--
ALTER TABLE `accesslogs`
  MODIFY `access_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '主鍵';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `actionlogs`
--
ALTER TABLE `actionlogs`
  MODIFY `action_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '主鍵';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `docdata`
--
ALTER TABLE `docdata`
  MODIFY `doc_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `docsubdata`
--
ALTER TABLE `docsubdata`
  MODIFY `sub_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '申請ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `enrollmentdata`
--
ALTER TABLE `enrollmentdata`
  MODIFY `enroll_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `filedata`
--
ALTER TABLE `filedata`
  MODIFY `file_ID` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `milesdata`
--
ALTER TABLE `milesdata`
  MODIFY `ms_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '里程碑ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `msgdata`
--
ALTER TABLE `msgdata`
  MODIFY `msg_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `pereviewdata`
--
ALTER TABLE `pereviewdata`
  MODIFY `peer_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '互評紀錄ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `perioddata`
--
ALTER TABLE `perioddata`
  MODIFY `period_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `petargetdata`
--
ALTER TABLE `petargetdata`
  MODIFY `pe_target_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `projectdata`
--
ALTER TABLE `projectdata`
  MODIFY `pro_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '專題ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `prosubdata`
--
ALTER TABLE `prosubdata`
  MODIFY `prosub_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `reprogressdata`
--
ALTER TABLE `reprogressdata`
  MODIFY `rp_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '紀錄ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `requirementdata`
--
ALTER TABLE `requirementdata`
  MODIFY `req_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '需求ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `suggest`
--
ALTER TABLE `suggest`
  MODIFY `suggest_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '主鍵', AUTO_INCREMENT=5;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `taskdata`
--
ALTER TABLE `taskdata`
  MODIFY `task_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '任務ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `teamapply`
--
ALTER TABLE `teamapply`
  MODIFY `tap_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `teamdata`
--
ALTER TABLE `teamdata`
  MODIFY `team_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '團隊ID', AUTO_INCREMENT=7;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `timedata`
--
ALTER TABLE `timedata`
  MODIFY `time_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `timeinformadata`
--
ALTER TABLE `timeinformadata`
  MODIFY `tinforma_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `typedata`
--
ALTER TABLE `typedata`
  MODIFY `type_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '主鍵', AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `workdata`
--
ALTER TABLE `workdata`
  MODIFY `work_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- 已傾印資料表的限制式
--

--
-- 資料表的限制式 `accesslogs`
--
ALTER TABLE `accesslogs`
  ADD CONSTRAINT `fk_ac_role` FOREIGN KEY (`role_ID`) REFERENCES `roledata` (`role_ID`),
  ADD CONSTRAINT `fk_ac_user` FOREIGN KEY (`u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `actionlogs`
--
ALTER TABLE `actionlogs`
  ADD CONSTRAINT `fk_al_role` FOREIGN KEY (`role_ID`) REFERENCES `roledata` (`role_ID`),
  ADD CONSTRAINT `fk_al_user` FOREIGN KEY (`u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `cohortdata`
--
ALTER TABLE `cohortdata`
  ADD CONSTRAINT `fk_cohort_status` FOREIGN KEY (`cohort_status`) REFERENCES `statusdata` (`status_ID`);

--
-- 資料表的限制式 `docdata`
--
ALTER TABLE `docdata`
  ADD CONSTRAINT `fk_doc_status` FOREIGN KEY (`doc_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_doc_user` FOREIGN KEY (`doc_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `docsubdata`
--
ALTER TABLE `docsubdata`
  ADD CONSTRAINT `fk_dcs_appr` FOREIGN KEY (`dc_approved_u_ID`) REFERENCES `userdata` (`u_ID`),
  ADD CONSTRAINT `fk_dcs_doc` FOREIGN KEY (`doc_ID`) REFERENCES `docdata` (`doc_ID`),
  ADD CONSTRAINT `fk_dcs_status` FOREIGN KEY (`dcsub_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_dcs_team` FOREIGN KEY (`dcsub_team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_dcs_user` FOREIGN KEY (`dcsub_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `doctargetdata`
--
ALTER TABLE `doctargetdata`
  ADD CONSTRAINT `fk_doct_doc` FOREIGN KEY (`doc_ID`) REFERENCES `docdata` (`doc_ID`);

--
-- 資料表的限制式 `enrollmentdata`
--
ALTER TABLE `enrollmentdata`
  ADD CONSTRAINT `fk_enroll_class` FOREIGN KEY (`class_ID`) REFERENCES `classdata` (`c_ID`),
  ADD CONSTRAINT `fk_enroll_cohort` FOREIGN KEY (`cohort_ID`) REFERENCES `cohortdata` (`cohort_ID`),
  ADD CONSTRAINT `fk_enroll_role` FOREIGN KEY (`role_ID`) REFERENCES `roledata` (`role_ID`),
  ADD CONSTRAINT `fk_enroll_status` FOREIGN KEY (`enroll_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_enroll_user` FOREIGN KEY (`enroll_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `groupdata`
--
ALTER TABLE `groupdata`
  ADD CONSTRAINT `fk_group_status` FOREIGN KEY (`group_status`) REFERENCES `statusdata` (`status_ID`);

--
-- 資料表的限制式 `milesdata`
--
ALTER TABLE `milesdata`
  ADD CONSTRAINT `fk_ms_apprusr` FOREIGN KEY (`ms_approved_u_ID`) REFERENCES `userdata` (`u_ID`),
  ADD CONSTRAINT `fk_ms_req` FOREIGN KEY (`req_ID`) REFERENCES `requirementdata` (`req_ID`),
  ADD CONSTRAINT `fk_ms_status` FOREIGN KEY (`ms_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_ms_team` FOREIGN KEY (`team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_ms_user` FOREIGN KEY (`ms_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `msgdata`
--
ALTER TABLE `msgdata`
  ADD CONSTRAINT `fk_msg_status` FOREIGN KEY (`msg_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_msg_user` FOREIGN KEY (`msg_a_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `msgreaddata`
--
ALTER TABLE `msgreaddata`
  ADD CONSTRAINT `fk_msgr_msg` FOREIGN KEY (`msg_ID`) REFERENCES `msgdata` (`msg_ID`),
  ADD CONSTRAINT `fk_msgr_user` FOREIGN KEY (`read_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `msgtargetdata`
--
ALTER TABLE `msgtargetdata`
  ADD CONSTRAINT `fk_msgt_msg` FOREIGN KEY (`msg_ID`) REFERENCES `msgdata` (`msg_ID`);

--
-- 資料表的限制式 `pereviewdata`
--
ALTER TABLE `pereviewdata`
  ADD CONSTRAINT `fk_prv_period` FOREIGN KEY (`period_ID`) REFERENCES `perioddata` (`period_ID`),
  ADD CONSTRAINT `fk_prv_target` FOREIGN KEY (`pe_target_ID`) REFERENCES `petargetdata` (`pe_target_ID`),
  ADD CONSTRAINT `fk_prv_user` FOREIGN KEY (`pe_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `perioddata`
--
ALTER TABLE `perioddata`
  ADD CONSTRAINT `fk_pe_user` FOREIGN KEY (`pe_created_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `petargetdata`
--
ALTER TABLE `petargetdata`
  ADD CONSTRAINT `fk_pet_class` FOREIGN KEY (`pe_class_ID`) REFERENCES `classdata` (`c_ID`),
  ADD CONSTRAINT `fk_pet_cohort` FOREIGN KEY (`pe_cohort_ID`) REFERENCES `cohortdata` (`cohort_ID`),
  ADD CONSTRAINT `fk_pet_period` FOREIGN KEY (`period_ID`) REFERENCES `perioddata` (`period_ID`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pet_team` FOREIGN KEY (`pe_team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_petarget_status` FOREIGN KEY (`status_ID`) REFERENCES `statusdata` (`status_ID`);

--
-- 資料表的限制式 `projectdata`
--
ALTER TABLE `projectdata`
  ADD CONSTRAINT `fk_pro_cohort` FOREIGN KEY (`pro_chorot_ID`) REFERENCES `cohortdata` (`cohort_ID`),
  ADD CONSTRAINT `fk_pro_status` FOREIGN KEY (`pro_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_pro_user` FOREIGN KEY (`pro_created_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `prosubdata`
--
ALTER TABLE `prosubdata`
  ADD CONSTRAINT `fk_psd_project` FOREIGN KEY (`pro_ID`) REFERENCES `projectdata` (`pro_ID`),
  ADD CONSTRAINT `fk_psd_status` FOREIGN KEY (`prosub_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_psd_team` FOREIGN KEY (`team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_psd_user1` FOREIGN KEY (`prosub_u_ID`) REFERENCES `userdata` (`u_ID`),
  ADD CONSTRAINT `fk_psd_user2` FOREIGN KEY (`prosub_re_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `reprogressdata`
--
ALTER TABLE `reprogressdata`
  ADD CONSTRAINT `fk_rp_apprusr` FOREIGN KEY (`rp_approved_u_ID`) REFERENCES `userdata` (`u_ID`),
  ADD CONSTRAINT `fk_rp_req` FOREIGN KEY (`req_ID`) REFERENCES `requirementdata` (`req_ID`),
  ADD CONSTRAINT `fk_rp_status` FOREIGN KEY (`rp_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_rp_team` FOREIGN KEY (`rp_team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_rp_user` FOREIGN KEY (`rp_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `requirementdata`
--
ALTER TABLE `requirementdata`
  ADD CONSTRAINT `fk_req_cohort` FOREIGN KEY (`cohort_ID`) REFERENCES `cohortdata` (`cohort_ID`),
  ADD CONSTRAINT `fk_req_edit_user` FOREIGN KEY (`edit_u_ID`) REFERENCES `userdata` (`u_ID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_req_group` FOREIGN KEY (`group_ID`) REFERENCES `groupdata` (`group_ID`),
  ADD CONSTRAINT `fk_req_status` FOREIGN KEY (`req_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_req_type` FOREIGN KEY (`type_ID`) REFERENCES `typedata` (`type_ID`),
  ADD CONSTRAINT `fk_req_user` FOREIGN KEY (`req_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `roledata`
--
ALTER TABLE `roledata`
  ADD CONSTRAINT `fk_role_status` FOREIGN KEY (`role_status`) REFERENCES `statusdata` (`status_ID`);

--
-- 資料表的限制式 `suggest`
--
ALTER TABLE `suggest`
  ADD CONSTRAINT `fk_sug_status` FOREIGN KEY (`suggest_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_sug_team` FOREIGN KEY (`team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_sug_user` FOREIGN KEY (`suggest_u_ID`) REFERENCES `userdata` (`u_ID`),
  ADD CONSTRAINT `fk_suggest_type` FOREIGN KEY (`type_ID`) REFERENCES `typedata` (`type_ID`);

--
-- 資料表的限制式 `taskdata`
--
ALTER TABLE `taskdata`
  ADD CONSTRAINT `fk_task_cohort` FOREIGN KEY (`task_cohort_ID`) REFERENCES `cohortdata` (`cohort_ID`),
  ADD CONSTRAINT `fk_task_milestone` FOREIGN KEY (`ms_ID`) REFERENCES `milesdata` (`ms_ID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_task_requirement` FOREIGN KEY (`req_ID`) REFERENCES `requirementdata` (`req_ID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_task_status` FOREIGN KEY (`task_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_task_team` FOREIGN KEY (`task_team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_task_user1` FOREIGN KEY (`task_u_ID`) REFERENCES `userdata` (`u_ID`),
  ADD CONSTRAINT `fk_task_user2` FOREIGN KEY (`task_done_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `teamapply`
--
ALTER TABLE `teamapply`
  ADD CONSTRAINT `fk_teamapply_reviewer` FOREIGN KEY (`tap_rp_u_ID`) REFERENCES `userdata` (`u_ID`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_teamapply_status` FOREIGN KEY (`tap_status`) REFERENCES `statusdata` (`status_ID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_teamapply_teacher` FOREIGN KEY (`tap_teacher`) REFERENCES `userdata` (`u_ID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_teamapply_user` FOREIGN KEY (`tap_u_ID`) REFERENCES `userdata` (`u_ID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 資料表的限制式 `teamdata`
--
ALTER TABLE `teamdata`
  ADD CONSTRAINT `fk_team_cohort` FOREIGN KEY (`cohort_ID`) REFERENCES `cohortdata` (`cohort_ID`),
  ADD CONSTRAINT `fk_team_group` FOREIGN KEY (`group_ID`) REFERENCES `groupdata` (`group_ID`),
  ADD CONSTRAINT `fk_team_status` FOREIGN KEY (`team_status`) REFERENCES `statusdata` (`status_ID`);

--
-- 資料表的限制式 `teammember`
--
ALTER TABLE `teammember`
  ADD CONSTRAINT `fk_tm_status` FOREIGN KEY (`tm_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_tm_user` FOREIGN KEY (`team_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `timedata`
--
ALTER TABLE `timedata`
  ADD CONSTRAINT `fk_time_team` FOREIGN KEY (`team_ID`) REFERENCES `teamdata` (`team_ID`),
  ADD CONSTRAINT `fk_time_tinforma` FOREIGN KEY (`tinforma_ID`) REFERENCES `timeinformadata` (`tinforma_ID`),
  ADD CONSTRAINT `fk_timedata_type` FOREIGN KEY (`type_ID`) REFERENCES `typedata` (`type_ID`);

--
-- 資料表的限制式 `typedata`
--
ALTER TABLE `typedata`
  ADD CONSTRAINT `fk_type_status` FOREIGN KEY (`type_status`) REFERENCES `statusdata` (`status_ID`);

--
-- 資料表的限制式 `userdata`
--
ALTER TABLE `userdata`
  ADD CONSTRAINT `fk_user_status` FOREIGN KEY (`u_status`) REFERENCES `statusdata` (`status_ID`);

--
-- 資料表的限制式 `userrolesdata`
--
ALTER TABLE `userrolesdata`
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_ID`) REFERENCES `roledata` (`role_ID`),
  ADD CONSTRAINT `fk_ur_status` FOREIGN KEY (`user_role_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`ur_u_ID`) REFERENCES `userdata` (`u_ID`);

--
-- 資料表的限制式 `workdata`
--
ALTER TABLE `workdata`
  ADD CONSTRAINT `fk_work_ms` FOREIGN KEY (`ms_ID`) REFERENCES `milesdata` (`ms_ID`),
  ADD CONSTRAINT `fk_work_req` FOREIGN KEY (`req_ID`) REFERENCES `requirementdata` (`req_ID`),
  ADD CONSTRAINT `fk_work_status` FOREIGN KEY (`work_status`) REFERENCES `statusdata` (`status_ID`),
  ADD CONSTRAINT `fk_work_task` FOREIGN KEY (`task_ID`) REFERENCES `taskdata` (`task_ID`),
  ADD CONSTRAINT `fk_work_user` FOREIGN KEY (`work_u_ID`) REFERENCES `userdata` (`u_ID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
