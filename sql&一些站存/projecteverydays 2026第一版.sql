-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： 127.0.0.1
-- 產生時間： 2026-01-02 10:39:33
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
  `page_url` varchar(255) DEFAULT NULL COMMENT '頁面路徑',
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
  `action_description` varchar(255) DEFAULT NULL COMMENT '描述動作',
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
  `cohort_status` tinyint(1) NOT NULL COMMENT '狀態'
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
  `doc_des` varchar(150) DEFAULT NULL COMMENT '說明',
  `doc_type` varchar(50) DEFAULT NULL COMMENT '副檔名清單',
  `doc_example` varchar(255) DEFAULT NULL COMMENT '範例文件',
  `is_top` tinyint(1) DEFAULT NULL COMMENT '是否置頂',
  `is_required` tinyint(1) DEFAULT NULL COMMENT '是否必要',
  `doc_start_d` datetime DEFAULT NULL COMMENT '開放時間',
  `doc_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `doc_status` tinyint(1) NOT NULL COMMENT '狀態',
  `doc_u_ID` varchar(25) DEFAULT NULL COMMENT '創建者',
  `doc_created_d` datetime DEFAULT NULL COMMENT '建立時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文件';

--
-- 傾印資料表的資料 `docdata`
--

INSERT INTO `docdata` (`doc_ID`, `doc_name`, `doc_des`, `doc_type`, `doc_example`, `is_top`, `is_required`, `doc_start_d`, `doc_end_d`, `doc_status`, `doc_u_ID`, `doc_created_d`) VALUES
(1, '123', '', 'pdf', 'uploads/doc/doc_20251124_045717_ba94.pdf', 0, 0, NULL, NULL, 1, 'uknim', '2025-11-24 11:57:17'),
(2, '異動表', '', 'pdf', 'uploads/doc/doc_20251127_061935_7097.pdf', 0, 0, NULL, NULL, 1, 'uknim', '2025-11-27 13:19:35'),
(3, '初審單', '', 'pdf', 'uploads/doc/doc_20251127_062056_4f20.pdf', 0, 1, NULL, NULL, 1, 'uknim', '2025-11-27 13:20:56'),
(4, '4學年度證照申請單', 'A', 'pdf', 'uploads/doc/doc_20251210_151659_30b0.pdf', 0, 1, '2025-12-10 10:00:00', '2025-12-15 23:55:00', 1, 'uknim', '2025-12-10 22:16:59'),
(5, '4學年度證照申請單', 'B', 'pdf', 'uploads/doc/doc_20251210_151726_9529.pdf', 0, 0, NULL, NULL, 1, 'uknim', '2025-12-10 22:17:26');

-- --------------------------------------------------------

--
-- 資料表結構 `docsubdata`
--

CREATE TABLE `docsubdata` (
  `sub_ID` int(11) NOT NULL COMMENT '申請ID',
  `doc_ID` int(11) NOT NULL COMMENT '文件',
  `dcsub_team_ID` int(11) DEFAULT NULL COMMENT '團隊',
  `dcsub_u_ID` varchar(25) DEFAULT NULL COMMENT '上傳者',
  `dcsub_comment` varchar(200) DEFAULT NULL COMMENT '說明文字',
  `dcsub_url` varchar(255) DEFAULT NULL COMMENT '文件位置',
  `dcsub_sub_d` datetime NOT NULL DEFAULT current_timestamp() COMMENT '上傳時間',
  `dc_approved_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `dcsub_approved_d` datetime DEFAULT NULL COMMENT '審核時間',
  `dcsub_remark` varchar(300) DEFAULT NULL COMMENT '審核備註',
  `dcsub_status` tinyint(1) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文件繳交';

--
-- 傾印資料表的資料 `docsubdata`
--

INSERT INTO `docsubdata` (`sub_ID`, `doc_ID`, `dcsub_team_ID`, `dcsub_u_ID`, `dcsub_comment`, `dcsub_url`, `dcsub_sub_d`, `dc_approved_u_ID`, `dcsub_approved_d`, `dcsub_remark`, `dcsub_status`) VALUES
(1, 4, 1, '110534221', 'AAA', 'uploads/images/img_69398193968ee.png', '2025-12-10 22:20:03', 'uknim', '2025-12-10 22:24:22', NULL, 1);

-- --------------------------------------------------------

--
-- 資料表結構 `doctargetdata`
--

CREATE TABLE `doctargetdata` (
  `doc_ID` int(11) NOT NULL COMMENT '文件ID',
  `doc_target_type` enum('ALL','COHORT','CLASS','TEAM','USER','GROUP') NOT NULL COMMENT '資料表',
  `doc_target_ID` varchar(50) NOT NULL COMMENT '目標ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文件目標';

--
-- 傾印資料表的資料 `doctargetdata`
--

INSERT INTO `doctargetdata` (`doc_ID`, `doc_target_type`, `doc_target_ID`) VALUES
(1, 'COHORT', '3'),
(1, 'CLASS', '1'),
(1, 'CLASS', '2'),
(2, 'COHORT', '3'),
(2, 'CLASS', '1'),
(2, 'CLASS', '2'),
(2, 'GROUP', '1'),
(2, 'GROUP', '2'),
(2, 'GROUP', '3'),
(3, 'GROUP', '2'),
(4, 'COHORT', '2'),
(4, 'COHORT', '3'),
(5, 'COHORT', '3');

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
  `enroll_status` tinyint(1) NOT NULL COMMENT '狀態',
  `enroll_created_d` datetime DEFAULT NULL COMMENT '建立時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='學籍屆別歷史紀錄表';

--
-- 傾印資料表的資料 `enrollmentdata`
--

INSERT INTO `enrollmentdata` (`enroll_ID`, `enroll_u_ID`, `cohort_ID`, `class_ID`, `role_ID`, `enroll_grade`, `enroll_status`, `enroll_created_d`) VALUES
(1, '109534201', 2, 2, 6, 5, 0, '2025-11-06 12:50:06'),
(2, '109534206', 2, 2, 6, 5, 0, '2025-11-06 12:50:06'),
(3, '109534207', 2, 2, 6, 5, 0, '2025-11-06 12:50:06'),
(4, '110511114', 3, 2, 6, 5, 1, '2025-11-06 12:50:06'),
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
(40, 'beckchou', 3, NULL, 4, NULL, 1, '2025-11-06 12:58:50'),
(41, 'toshiko', 3, NULL, 4, NULL, 1, '2025-11-06 12:58:50'),
(42, 'system', 3, NULL, 0, NULL, 1, '2025-11-06 12:58:50'),
(43, 'uknim', 3, NULL, 2, NULL, 1, '2025-11-06 12:58:50'),
(47, '110534202', 3, 2, 6, 5, 1, '2025-11-13 10:21:43'),
(48, '110534207', 3, 2, 6, 5, 1, '2025-11-13 10:21:43'),
(49, '110534235', 3, 2, 6, 5, 1, '2025-11-13 10:21:43'),
(50, '110534242', 3, 2, 6, 5, 1, '2025-11-13 10:21:43'),
(51, '110534108', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(52, '110534109', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(53, '110534133', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(54, '110534107', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(55, '110534132', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(56, '110534110', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(57, '110534119', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(58, '110534134', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(59, '110534123', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(60, '110534120', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(61, '110534101', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(62, '110534114', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(63, '110534145', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(64, '110534105', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(65, '110534113', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(66, '110534125', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(67, '110534137', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(68, '110534104', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(69, '110534118', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(70, '110534138', 3, 1, 6, 5, 1, '2025-11-24 09:59:02'),
(71, 'toshiko', 2, 2, 4, 5, 0, '2025-12-01 16:12:38'),
(72, 'beckchou', 2, NULL, 4, NULL, 0, '2025-12-01 16:12:38'),
(73, 'claire', 2, NULL, 4, NULL, 0, '2025-11-24 10:09:35'),
(74, 'wennie', 2, NULL, 4, NULL, 0, '2025-11-24 10:09:35'),
(75, 'jack', 2, NULL, 4, NULL, 0, '2025-11-24 10:09:35'),
(76, 'scottli', 2, NULL, 4, NULL, 0, '2025-11-24 10:09:35'),
(77, 'yang', 2, NULL, 4, NULL, 0, '2025-11-24 10:09:35'),
(78, 'stanleyh', 2, NULL, 4, NULL, 0, '2025-11-24 10:09:35'),
(79, 'yfpeng', 2, NULL, 4, NULL, 0, '2025-11-24 10:09:35'),
(80, 'claire', 3, NULL, 4, NULL, 1, '2025-11-24 10:09:35'),
(81, 'wennie', 3, NULL, 4, NULL, 1, '2025-11-24 10:09:35'),
(82, 'jack', 3, NULL, 4, NULL, 1, '2025-11-24 10:09:35'),
(83, 'scottli', 3, NULL, 4, NULL, 1, '2025-11-24 10:09:35'),
(84, 'yang', 3, NULL, 4, NULL, 1, '2025-11-24 10:09:35'),
(85, 'stanleyh', 3, NULL, 4, NULL, 1, '2025-11-24 10:09:35'),
(86, 'yfpeng', 3, NULL, 4, NULL, 1, '2025-11-24 10:09:35'),
(87, '110534136', 3, 1, 6, 5, 1, '2025-12-01 16:12:38'),
(88, 'toshiko', 4, 2, 3, 4, 1, '2025-12-01 16:12:38'),
(89, 'toshiko', 2, 2, 3, 5, 0, '2025-12-01 16:12:38'),
(90, 'beckchou', 3, 2, 3, 5, 1, '2025-12-01 16:12:38'),
(91, '110534226', 3, 2, 6, 5, 1, '2025-12-01 16:12:38'),
(92, '110534228', 3, 2, 6, 5, 1, '2025-12-01 16:12:38'),
(93, '110534232', 3, 2, 6, 5, 1, '2025-12-01 16:12:38'),
(94, '110534233', 3, 2, 6, 5, 1, '2025-12-01 16:12:38'),
(95, '110534237', 3, 2, 6, 5, 1, '2025-12-01 16:12:38'),
(96, '110534238', 3, 2, 6, 5, 1, '2025-12-01 16:12:38'),
(97, '110534229', 3, 2, 6, 5, 1, '2025-12-01 16:12:38'),
(98, 'claire', 3, 1, 3, 5, 1, '2025-12-05 14:02:06'),
(99, 'uknim', 3, NULL, 1, NULL, 0, '2025-12-10 17:56:36'),
(100, 'uknim', 3, NULL, 4, NULL, 0, '2025-12-10 17:56:53'),
(101, 'uknim', 3, 2, 3, 5, 1, '2025-12-10 17:56:53'),
(102, '110534115', 3, 1, 6, 5, 1, '2025-12-11 09:59:38'),
(103, '110534116', 3, 1, 6, 5, 1, '2025-12-11 09:59:38'),
(104, 'test1', 1, NULL, 6, 3, 1, '2025-12-26 15:48:36'),
(105, 'test2', 1, 2, 5, 1, 1, '2025-12-26 15:50:18'),
(106, 'test3', 3, 2, 4, 2, 1, '2025-12-26 15:53:18');

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
  `group_status` tinyint(1) NOT NULL COMMENT '狀態',
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
-- 資料表結構 `grouptypedata`
--

CREATE TABLE `grouptypedata` (
  `type_ID` int(11) NOT NULL COMMENT '細項類組ID',
  `group_ID` int(11) NOT NULL COMMENT 'groupID',
  `type_name` varchar(50) NOT NULL COMMENT '細項名稱',
  `type_status` tinyint(1) DEFAULT 1 COMMENT '狀態',
  `type_created_d` datetime DEFAULT current_timestamp() COMMENT '建立時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `milesdata`
--

CREATE TABLE `milesdata` (
  `ms_ID` int(11) NOT NULL COMMENT '里程碑ID',
  `req_ID` int(11) DEFAULT NULL COMMENT '基本需求ID',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `ms_title` varchar(150) NOT NULL COMMENT '標題',
  `ms_desc` varchar(200) DEFAULT NULL COMMENT '內容',
  `ms_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `ms_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `ms_u_ID` varchar(25) DEFAULT NULL COMMENT '完成者',
  `ms_url` varchar(255) DEFAULT NULL COMMENT '檔案位置',
  `ms_completed_d` datetime DEFAULT NULL COMMENT '完成時間',
  `ms_approved_d` datetime DEFAULT NULL COMMENT '通過時間',
  `ms_approved_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `ms_status` tinyint(1) NOT NULL COMMENT '狀態',
  `ms_priority` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=一般, 1=重要, 2=緊急, 3=超級緊急',
  `ms_created_d` datetime DEFAULT NULL COMMENT '建立人'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='里程碑';

--
-- 傾印資料表的資料 `milesdata`
--

INSERT INTO `milesdata` (`ms_ID`, `req_ID`, `team_ID`, `ms_title`, `ms_desc`, `ms_start_d`, `ms_end_d`, `ms_u_ID`, `ms_url`, `ms_completed_d`, `ms_approved_d`, `ms_approved_u_ID`, `ms_status`, `ms_priority`, `ms_created_d`) VALUES
(1, NULL, 1, '完成登入系統', '', '2025-12-04 11:43:00', '2025-12-06 04:37:00', 'uknim', NULL, '2026-01-02 17:31:05', NULL, NULL, 4, 1, '2025-12-04 04:37:54');

-- --------------------------------------------------------

--
-- 資料表結構 `msgdata`
--

CREATE TABLE `msgdata` (
  `msg_ID` int(11) NOT NULL,
  `msg_title` varchar(100) NOT NULL COMMENT '標題',
  `msg_content` varchar(255) DEFAULT NULL COMMENT '內容',
  `msg_url` varchar(255) DEFAULT NULL COMMENT '可放JSON陣列：圖片/URL/PDF等',
  `msg_a_u_ID` varchar(25) DEFAULT NULL COMMENT '創建人',
  `priority` int(11) DEFAULT NULL COMMENT '跑馬燈排序：越大越前',
  `msg_type` enum('ANNOUNCEMENT','SYSTEM_NOTICE','REMINDER') NOT NULL COMMENT '類型、區分用途',
  `msg_status` tinyint(1) NOT NULL COMMENT '狀態',
  `msg_start_d` datetime DEFAULT NULL COMMENT '發布時間',
  `msg_end_d` datetime DEFAULT NULL COMMENT '結束時間',
  `msg_created_d` datetime DEFAULT NULL COMMENT '創建時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='公告、通知';

--
-- 傾印資料表的資料 `msgdata`
--

INSERT INTO `msgdata` (`msg_ID`, `msg_title`, `msg_content`, `msg_url`, `msg_a_u_ID`, `priority`, `msg_type`, `msg_status`, `msg_start_d`, `msg_end_d`, `msg_created_d`) VALUES
(1, 'test', 'test', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, NULL, NULL, NULL),
(19, '未加入團隊學生通知', '您好，該班級有 20 位學生尚未加入團隊：\n\n林宜伶（110534101）、葉芃孝（110534104）、顏劭沂（110534105）、唐佳臻（110534107）、林妍妡（110534108）、蕭鈺萱（110534109）、陳宥瑋（110534110）、林永欽（110534113）、楊勝維（110534114）、潘星穎（110534118）、伏詠琳（110534119）、石盛文（110534120）、楊翔安（110534123）、王育圻（110534125）、郭芷妍（110534132）、鍾雨凝（110', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 11:45:40', NULL, '2025-12-05 11:45:40'),
(20, '未加入團隊學生通知', '您好，該班級有 12 位學生尚未加入團隊：\n\n林奕璁（110534209）、汪俊成（110534210）、陳思維（110534211）、賴定宏（110534212）、蔡承達（110534224）、謝秉哲（110534228）、吳嘉緯（110534229）、蔡愷慈（110534232）、李玉馨（110534233）、蔡宏恩（110534237）、黃哲渝（110534238）、郭紘丞（110534242）\n\n請協助提醒學生加入團隊。', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 11:45:40', NULL, '2025-12-05 11:45:40'),
(21, '未加入團隊學生通知', '您好，該班級有 20 位學生尚未加入團隊：\n\n林宜伶（110534101）、葉芃孝（110534104）、顏劭沂（110534105）、唐佳臻（110534107）、林妍妡（110534108）、蕭鈺萱（110534109）、陳宥瑋（110534110）、林永欽（110534113）、楊勝維（110534114）、潘星穎（110534118）、伏詠琳（110534119）、石盛文（110534120）、楊翔安（110534123）、王育圻（110534125）、郭芷妍（110534132）、鍾雨凝（110', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 11:55:25', NULL, '2025-12-05 11:55:25'),
(22, '未加入團隊學生通知', '您好，該班級有 12 位學生尚未加入團隊：\n\n林奕璁（110534209）、汪俊成（110534210）、陳思維（110534211）、賴定宏（110534212）、蔡承達（110534224）、謝秉哲（110534228）、吳嘉緯（110534229）、蔡愷慈（110534232）、李玉馨（110534233）、蔡宏恩（110534237）、黃哲渝（110534238）、郭紘丞（110534242）\n\n請協助提醒學生加入團隊。', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 11:55:25', NULL, '2025-12-05 11:55:25'),
(23, '未加入團隊學生通知', '您好，該班級有 1 位學生尚未加入團隊：\n\n林宜伶（110534101）\n\n請協助提醒學生加入團隊。', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 12:52:16', NULL, '2025-12-05 12:52:16'),
(24, '未加入團隊學生通知', '您好，該班級有 20 位學生尚未加入團隊：\n\n林宜伶（110534101）、葉芃孝（110534104）、顏劭沂（110534105）、唐佳臻（110534107）、林妍妡（110534108）、蕭鈺萱（110534109）、陳宥瑋（110534110）、林永欽（110534113）、楊勝維（110534114）、潘星穎（110534118）、伏詠琳（110534119）、石盛文（110534120）、楊翔安（110534123）、王育圻（110534125）、郭芷妍（110534132）、鍾雨凝（110', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 14:00:21', NULL, '2025-12-05 14:00:21'),
(25, '未加入團隊學生通知', '您好，該班級有 12 位學生尚未加入團隊：\n\n林奕璁（110534209）、汪俊成（110534210）、陳思維（110534211）、賴定宏（110534212）、蔡承達（110534224）、謝秉哲（110534228）、吳嘉緯（110534229）、蔡愷慈（110534232）、李玉馨（110534233）、蔡宏恩（110534237）、黃哲渝（110534238）、郭紘丞（110534242）\n\n請協助提醒學生加入團隊。', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 14:00:21', NULL, '2025-12-05 14:00:21'),
(26, '專題申請通知', '學生 林宜伶 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-12-05 18:44:01', NULL, '2025-12-05 18:44:01'),
(27, '未加入團隊學生通知', '您好，該班級有 20 位學生尚未加入團隊：\n\n林宜伶（110534101）、葉芃孝（110534104）、顏劭沂（110534105）、唐佳臻（110534107）、林妍妡（110534108）、蕭鈺萱（110534109）、陳宥瑋（110534110）、林永欽（110534113）、楊勝維（110534114）、潘星穎（110534118）、伏詠琳（110534119）、石盛文（110534120）、楊翔安（110534123）、王育圻（110534125）、郭芷妍（110534132）、鍾雨凝（110', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-10 16:13:38', NULL, '2025-12-10 16:13:38'),
(28, '未加入團隊學生通知', '您好，該班級有 12 位學生尚未加入團隊：\n\n林奕璁（110534209）、汪俊成（110534210）、陳思維（110534211）、賴定宏（110534212）、蔡承達（110534224）、謝秉哲（110534228）、吳嘉緯（110534229）、蔡愷慈（110534232）、李玉馨（110534233）、蔡宏恩（110534237）、黃哲渝（110534238）、郭紘丞（110534242）\n\n請協助提醒學生加入團隊。', NULL, 'uknim', NULL, 'SYSTEM_NOTICE', 1, '2025-12-10 16:13:38', NULL, '2025-12-10 16:13:38'),
(29, '專題申請通知', '學生 潘星穎 提交了專題申請表，請前往審核。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2025-12-11 13:13:34', NULL, '2025-12-11 13:13:34'),
(30, '里程碑完成通知', '團隊「專題日總彙」的里程碑「完成登入系統」已由 科辦 標記為完成。', NULL, 'system', NULL, 'SYSTEM_NOTICE', 1, '2026-01-02 17:31:05', NULL, '2026-01-02 17:31:05');

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
(1, 'uknim', '2025-12-05 13:36:58'),
(19, 'claire', '2025-12-05 12:55:27'),
(20, 'beckchou', '2025-12-05 11:51:53'),
(21, 'claire', '2025-12-05 12:55:27'),
(22, 'beckchou', '2025-12-05 11:55:41'),
(23, 'claire', '2025-12-05 12:55:27'),
(26, 'uknim', '2025-12-10 10:46:45'),
(29, 'uknim', '2026-01-02 17:29:06');

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
(19, 'USER', 'claire'),
(20, 'USER', 'beckchou'),
(21, 'USER', 'claire'),
(22, 'USER', 'beckchou'),
(23, 'USER', 'claire'),
(24, 'USER', 'claire'),
(25, 'USER', 'beckchou'),
(26, 'USER', 'uknim'),
(27, 'USER', 'claire'),
(28, 'USER', 'beckchou'),
(29, 'USER', 'uknim'),
(30, 'USER', 'beckchou'),
(30, 'USER', 'toshiko'),
(30, 'USER', 'uknim');

-- --------------------------------------------------------

--
-- 資料表結構 `pereviewdata`
--

CREATE TABLE `pereviewdata` (
  `peer_ID` int(11) NOT NULL COMMENT '互評紀錄ID',
  `period_ID` int(11) NOT NULL COMMENT '評分ID',
  `pe_target_ID` int(11) NOT NULL COMMENT '目標ID（petargetdata）',
  `pe_u_ID` varchar(25) NOT NULL COMMENT '評分者ID',
  `score` tinyint(1) DEFAULT NULL COMMENT '星等評分',
  `peer_comment` varchar(150) DEFAULT NULL COMMENT '評論',
  `created_d` datetime DEFAULT NULL COMMENT '評論時間',
  `petarget_u_ID` varchar(25) DEFAULT NULL COMMENT '被評分人ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='存放互評紀錄';

-- --------------------------------------------------------

--
-- 資料表結構 `perioddata`
--

CREATE TABLE `perioddata` (
  `period_ID` int(11) NOT NULL COMMENT '流水號',
  `period_title` varchar(100) NOT NULL COMMENT '標題',
  `period_type` varchar(255) NOT NULL COMMENT '互評方式',
  `period_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `period_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `pe_created_d` datetime DEFAULT NULL COMMENT '建立時間',
  `pe_created_u_ID` varchar(25) NOT NULL COMMENT '建立者',
  `pe_target_ID` varchar(255) DEFAULT NULL,
  `pe_role_ID` int(11) DEFAULT NULL COMMENT '創建時的角色ID'
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
  `status_ID` tinyint(1) DEFAULT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='存放互評目標（多對一：period→targets）';

-- --------------------------------------------------------

--
-- 資料表結構 `projectdata`
--

CREATE TABLE `projectdata` (
  `pro_ID` int(11) NOT NULL COMMENT '專題ID',
  `pro_chorot_ID` int(11) NOT NULL COMMENT '屆ID',
  `pro_title` varchar(100) NOT NULL COMMENT '標題',
  `pro_des` varchar(255) DEFAULT NULL COMMENT '內容',
  `pro_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `pro_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `pro_type` varchar(200) DEFAULT NULL COMMENT '文件格式',
  `pro_example` varchar(255) DEFAULT NULL COMMENT '範例文件',
  `pro_status` tinyint(1) NOT NULL COMMENT '狀態',
  `pro_created_u_ID` varchar(25) DEFAULT NULL COMMENT '創建者',
  `pro_created_d` datetime DEFAULT NULL COMMENT '創建時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='歷屆專題';

--
-- 傾印資料表的資料 `projectdata`
--

INSERT INTO `projectdata` (`pro_ID`, `pro_chorot_ID`, `pro_title`, `pro_des`, `pro_start_d`, `pro_end_d`, `pro_type`, `pro_example`, `pro_status`, `pro_created_u_ID`, `pro_created_d`) VALUES
(1, 3, '110學年度匯上傳', '{\"class_ID\":[]}', '2025-12-10 10:00:00', '2025-12-10 23:56:00', NULL, NULL, 1, 'uknim', '2025-12-10 22:15:23'),
(2, 3, '110學年度匯上傳', '{\"class_ID\":[]}', '2025-12-10 10:00:00', '2025-12-10 23:56:00', NULL, NULL, 1, 'uknim', '2025-12-10 22:15:23');

-- --------------------------------------------------------

--
-- 資料表結構 `prosubdata`
--

CREATE TABLE `prosubdata` (
  `prosub_ID` int(11) NOT NULL COMMENT '流水號',
  `pro_ID` int(11) NOT NULL COMMENT '專題資料ID',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `prosub_img` varchar(255) DEFAULT NULL COMMENT '海報',
  `prosub_other` varchar(255) DEFAULT NULL COMMENT '多個檔案',
  `content_json` varchar(255) DEFAULT NULL COMMENT '備用JSON欄位',
  `prosub_u_ID` varchar(25) DEFAULT NULL COMMENT '繳交人',
  `prosub_created_d` datetime DEFAULT NULL COMMENT '繳交時間',
  `prosub_reason` varchar(100) DEFAULT NULL COMMENT '申請修改原因',
  `prosub_re_reason` varchar(100) DEFAULT NULL COMMENT '審核備註',
  `prosub_re_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `prosub_re_d` datetime DEFAULT NULL COMMENT '審核時間',
  `prosub_status` tinyint(1) NOT NULL COMMENT '狀態',
  `prosub_update_d` datetime DEFAULT NULL COMMENT '更新時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='歷屆專題繳交資料';

--
-- 傾印資料表的資料 `prosubdata`
--

INSERT INTO `prosubdata` (`prosub_ID`, `pro_ID`, `team_ID`, `prosub_img`, `prosub_other`, `content_json`, `prosub_u_ID`, `prosub_created_d`, `prosub_reason`, `prosub_re_reason`, `prosub_re_u_ID`, `prosub_re_d`, `prosub_status`, `prosub_update_d`) VALUES
(1, 1, 1, 'uploads/project_posters/poster_1_1765376377_69398179a0991.png', NULL, '{\"intro\":\"我們專題日總彙主要想....\",\"is_deleted\":false,\"original_draft_id\":1,\"history\":[{\"action\":\"submitted\",\"from_draft_id\":1,\"submitted_by\":\"110534221\",\"submitted_at\":\"2025-12-10 15:19:39\",\"snapshot\":{\"intro\":\"我們專題日總彙主要想....\",\"image\":\"uploads\\/project_posters\\/p', '110534221', '2025-12-10 22:19:39', NULL, NULL, 'uknim', '2025-12-10 22:20:35', 3, '2025-12-10 22:20:44');

-- --------------------------------------------------------

--
-- 資料表結構 `reprogressdata`
--

CREATE TABLE `reprogressdata` (
  `rp_ID` int(11) NOT NULL COMMENT '紀錄ID',
  `req_ID` int(11) NOT NULL COMMENT '基本需求',
  `rp_team_ID` int(11) DEFAULT NULL COMMENT '團隊',
  `rp_u_ID` varchar(25) DEFAULT NULL COMMENT '完成者',
  `rp_count` varchar(5) NOT NULL COMMENT '量化',
  `rp_comment` varchar(100) DEFAULT NULL COMMENT '輸入欄位',
  `rp_status` tinyint(1) NOT NULL COMMENT '狀態',
  `rp_completed_d` datetime DEFAULT NULL COMMENT '完成時間',
  `rp_approved_d` datetime DEFAULT NULL COMMENT '審核時間',
  `rp_approved_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `rp_remark` varchar(255) DEFAULT NULL COMMENT '說明'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='基本需求紀錄';

--
-- 傾印資料表的資料 `reprogressdata`
--

INSERT INTO `reprogressdata` (`rp_ID`, `req_ID`, `rp_team_ID`, `rp_u_ID`, `rp_count`, `rp_comment`, `rp_status`, `rp_completed_d`, `rp_approved_d`, `rp_approved_u_ID`, `rp_remark`) VALUES
(2, 9, 1, '110534205', '43', 'https://docs.google.com/document/d/164hSV8fID2zu7_l_32y6nDNzV6dx_8Nh1JXNnKf9a1w/edit?usp=sharing', 3, '2025-12-11 02:56:01', '2025-12-11 02:57:51', NULL, '資料表很多，串聯一定要清楚。'),
(3, 11, 1, '110534215', '5', '主任、科辦、指導老師、學生、班導', 2, '2025-12-11 02:57:13', '2025-12-11 02:58:04', NULL, '請說明清楚!'),
(4, 10, 1, '110534231', '', '請參考線上的文件\nhttps://docs.google.com/document/d/164hSV8fID2zu7_l_32y6nDNzV6dx_8Nh1JXNnKf9a1w/edit?usp=sh', 1, '2025-12-11 02:58:48', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `requirementdata`
--

CREATE TABLE `requirementdata` (
  `req_ID` int(11) NOT NULL COMMENT '需求ID',
  `cohort_ID` int(11) DEFAULT NULL COMMENT '屆別ID',
  `group_ID` int(11) DEFAULT NULL COMMENT '類組ID',
  `type_ID` int(11) DEFAULT NULL COMMENT '分類',
  `req_title` varchar(100) NOT NULL COMMENT '需求標題',
  `req_direction` varchar(100) DEFAULT NULL COMMENT '需求說明',
  `req_count` varchar(5) DEFAULT NULL COMMENT '需求量化',
  `req_u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `color_hex` varchar(7) DEFAULT NULL COMMENT '顏色(甘特圖顯示)',
  `req_status` tinyint(1) NOT NULL COMMENT '狀態',
  `req_created_d` datetime DEFAULT NULL COMMENT '創建時間',
  `edit_u_ID` varchar(25) DEFAULT NULL COMMENT '最後編輯者帳號',
  `req_update_d` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '最後編輯時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='基本需求';

--
-- 傾印資料表的資料 `requirementdata`
--

INSERT INTO `requirementdata` (`req_ID`, `cohort_ID`, `group_ID`, `type_ID`, `req_title`, `req_direction`, `req_count`, `req_u_ID`, `color_hex`, `req_status`, `req_created_d`, `edit_u_ID`, `req_update_d`) VALUES
(8, 3, 1, 1, '應使用關聯式資料庫', '', '[]', 'toshiko', '#FFEE66', 1, '2025-12-11 02:41:27', NULL, '0000-00-00 00:00:00'),
(9, 3, 1, 1, '應至少有6個資料表(含以上)', '', '[\"資料表', 'toshiko', '#FFEE66', 1, '2025-12-11 02:41:48', NULL, '0000-00-00 00:00:00'),
(10, 3, 1, 1, '資料庫應有基本的正規化。', '', '[]', 'toshiko', '#FFEE66', 1, '2025-12-11 02:42:03', NULL, '0000-00-00 00:00:00'),
(11, 3, 1, 1, '至少有2種使用角色(含以上，不含訪客)', '', '[\"使用角', 'toshiko', '#FFEE66', 1, '2025-12-11 02:42:27', 'uknim', '2025-12-11 02:45:35'),
(12, 3, 1, 1, '至少1種統計資料，並能以表格或圖形呈現', '', '[\"統計資', 'toshiko', '#FFEE66', 1, '2025-12-11 02:42:54', NULL, '0000-00-00 00:00:00'),
(13, 3, 2, 1, '要有品牌故事、品牌定位、STP', '', '[]', 'uknim', '#FFEE66', 1, '2025-12-11 02:43:42', NULL, '0000-00-00 00:00:00'),
(14, 3, 2, 1, '至少要有三個月的銷售資料', '', '[]', 'uknim', '#FFEE66', 1, '2025-12-11 02:43:51', NULL, '0000-00-00 00:00:00'),
(15, 3, 2, 1, '依據銷售數據，每週提出檢討報告', '', '[]', 'uknim', '#FFEE66', 1, '2025-12-11 02:44:08', NULL, '0000-00-00 00:00:00'),
(16, 3, 2, 1, '請使用多種行銷方式推廣網路商品。', '請針對標題、關鍵字、商場圖片、商品品項、銷售方式、增加流量、提高回購等多種行銷方式進行網路商品的推廣。', '[]', 'uknim', '#FFEE66', 1, '2025-12-11 02:45:05', NULL, '0000-00-00 00:00:00');

-- --------------------------------------------------------

--
-- 資料表結構 `roledata`
--

CREATE TABLE `roledata` (
  `role_ID` int(11) NOT NULL COMMENT '角色',
  `role_name` varchar(25) NOT NULL COMMENT '角色名稱',
  `role_direction` varchar(50) DEFAULT NULL COMMENT '角色說明',
  `role_created_d` datetime NOT NULL COMMENT '創建時間',
  `role_status` tinyint(1) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='使用角色';

--
-- 傾印資料表的資料 `roledata`
--

INSERT INTO `roledata` (`role_ID`, `role_name`, `role_direction`, `role_created_d`, `role_status`) VALUES
(0, '系統', '針對系統寫出的非登入帳號，用於自動發送通知', '2025-11-06 11:30:41', 1),
(1, '主任', '科上主任', '2025-11-06 11:30:41', 1),
(2, '科辦', '系科科辦', '2025-11-06 11:30:41', 1),
(3, '班導', '班級導師', '2025-11-06 11:30:41', 1),
(4, '指導老師', '專題進行中，指導自己名下團隊中學生的老師', '2025-11-06 11:30:41', 1),
(5, '訪客', '訪客', '2025-11-06 11:30:41', 1),
(6, '學生', '學生', '2025-11-06 11:30:41', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `statusdata`
--

CREATE TABLE `statusdata` (
  `status_ID` tinyint(1) NOT NULL COMMENT '狀態ID',
  `status_name` varchar(15) NOT NULL COMMENT '狀態名稱'
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
  `sf_ID` int(11) NOT NULL COMMENT '建議所屬表單 ID',
  `suggest_ID` int(11) NOT NULL COMMENT '主鍵',
  `suggest_u_ID` varchar(25) NOT NULL COMMENT '評論者',
  `team_ID` int(11) NOT NULL COMMENT '被評論團隊',
  `suggest_comment` varchar(255) DEFAULT NULL COMMENT '評論內容',
  `suggest_d` datetime DEFAULT NULL COMMENT '評論時間',
  `suggest_status` tinyint(1) DEFAULT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='期中期末建議';

--
-- 傾印資料表的資料 `suggest`
--

INSERT INTO `suggest` (`sf_ID`, `suggest_ID`, `suggest_u_ID`, `team_ID`, `suggest_comment`, `suggest_d`, `suggest_status`) VALUES
(1, 4, 'uknim', 4, '1. test', '2025-12-05 17:19:55', 3),
(1, 5, 'uknim', 6, '1. test', '2025-12-05 17:19:55', 2),
(1, 6, 'uknim', 1, '1. test', '2025-12-05 17:19:55', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `suggestfrom`
--

CREATE TABLE `suggestfrom` (
  `sf_ID` int(11) NOT NULL COMMENT '流水號',
  `sf_name` varchar(100) NOT NULL COMMENT '標題',
  `sf_u_ID` varchar(25) NOT NULL COMMENT '建立者',
  `sf_created_d` datetime DEFAULT current_timestamp() COMMENT '建立日期',
  `sf_update_d` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `suggestfrom`
--

INSERT INTO `suggestfrom` (`sf_ID`, `sf_name`, `sf_u_ID`, `sf_created_d`, `sf_update_d`) VALUES
(1, '110級系統組期中建議', 'uknim', '2025-12-05 17:19:55', '2025-12-05 17:19:55');

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
  `task_desc` varchar(200) DEFAULT NULL COMMENT '內容',
  `task_start_d` datetime DEFAULT NULL COMMENT '開始時間',
  `task_end_d` datetime DEFAULT NULL COMMENT '截止時間',
  `task_done_u_ID` varchar(25) DEFAULT NULL COMMENT '完成人',
  `task_done_d` datetime DEFAULT NULL COMMENT '完成時間',
  `task_status` tinyint(1) NOT NULL COMMENT '狀態',
  `task_priority` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=一般, 1=重要, 2=緊急, 3=超級緊急',
  `task_created_d` datetime DEFAULT NULL COMMENT '建立時間',
  `task_url` varchar(255) DEFAULT NULL COMMENT '檔案位置'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='任務';

--
-- 傾印資料表的資料 `taskdata`
--

INSERT INTO `taskdata` (`task_ID`, `task_team_ID`, `task_u_ID`, `task_cohort_ID`, `ms_ID`, `req_ID`, `task_title`, `task_desc`, `task_start_d`, `task_end_d`, `task_done_u_ID`, `task_done_d`, `task_status`, `task_priority`, `task_created_d`, `task_url`) VALUES
(1, 1, '110534205', 3, NULL, NULL, '請完善共用文件的資料字典資料', '12/11要報告了！', NULL, '2025-12-11 02:50:00', '110534231', NULL, 1, 4, '2025-12-11 02:50:48', NULL),
(2, 1, '110534205', 3, NULL, NULL, '大家都要熟讀PPT內容！！！！！', NULL, '0000-00-00 00:00:00', '0000-00-00 00:00:00', NULL, NULL, 0, 4, '2025-12-11 02:51:18', NULL),
(3, 1, '110534205', 3, NULL, NULL, '任務區有小bug要記得修', NULL, '0000-00-00 00:00:00', '0000-00-00 00:00:00', '110534205', NULL, 1, 1, '2025-12-11 02:51:30', NULL),
(4, 1, '110534221', 3, NULL, NULL, '歷屆專題要增加篩選功能', '不是主要功能，代辦', NULL, NULL, '110534221', NULL, 1, 1, '2025-12-11 02:52:14', NULL),
(5, 1, '110534221', 3, NULL, NULL, '互評好像有跑版?', NULL, '0000-00-00 00:00:00', '0000-00-00 00:00:00', '110534215', NULL, 1, 2, '2025-12-11 02:52:36', NULL),
(7, 1, '110534205', 3, NULL, NULL, '任務區美版', NULL, '2025-12-01 14:54:00', NULL, '110534205', '2025-12-11 02:54:27', 3, 3, '2025-12-11 02:54:25', NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `teacherteamlimit`
--

CREATE TABLE `teacherteamlimit` (
  `ttl_ID` int(11) NOT NULL COMMENT '流水號',
  `ttl_u_ID` varchar(25) NOT NULL COMMENT '老師ID',
  `cohort_ID` int(11) NOT NULL COMMENT '屆別',
  `max_count` tinyint(4) NOT NULL COMMENT '團隊數量',
  `created_at` datetime DEFAULT current_timestamp() COMMENT '建立時間',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '最後更新時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `teacherteamlimit`
--

INSERT INTO `teacherteamlimit` (`ttl_ID`, `ttl_u_ID`, `cohort_ID`, `max_count`, `created_at`, `updated_at`) VALUES
(1, 'wennie', 3, 3, '2026-01-02 15:33:30', '2026-01-02 15:33:30'),
(2, 'beckchou', 3, 0, '2026-01-02 16:34:37', '2026-01-02 16:51:35');

-- --------------------------------------------------------

--
-- 資料表結構 `teamapply`
--

CREATE TABLE `teamapply` (
  `tap_ID` int(11) NOT NULL COMMENT '流水號',
  `tap_name` varchar(20) NOT NULL COMMENT '團隊名稱',
  `tap_member` varchar(255) DEFAULT NULL COMMENT '團隊成員(JSON字串)',
  `tap_teacher` varchar(25) NOT NULL COMMENT '指導老師',
  `tap_url` varchar(255) DEFAULT NULL COMMENT '提交檔案',
  `tap_des` varchar(200) DEFAULT NULL COMMENT '說明文字',
  `tap_status` tinyint(1) NOT NULL COMMENT '狀態',
  `tap_u_ID` varchar(25) NOT NULL COMMENT '提交者',
  `tap_rp_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人(關聯u_ID)',
  `tap_rp_d` datetime DEFAULT NULL COMMENT '審核時間',
  `tap_update_d` datetime DEFAULT NULL COMMENT '更新時間',
  `tap_remark` varchar(100) DEFAULT NULL COMMENT '審核人的備註'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 傾印資料表的資料 `teamapply`
--

INSERT INTO `teamapply` (`tap_ID`, `tap_name`, `tap_member`, `tap_teacher`, `tap_url`, `tap_des`, `tap_status`, `tap_u_ID`, `tap_rp_u_ID`, `tap_rp_d`, `tap_update_d`, `tap_remark`) VALUES
(1, '321', '[\"110534210\",\"110534212\"]', 'toshiko', 'uploads/team_apply/apply_110534212_1763913542.jpg', '{\"group_id\":\"2\",\"comment\":\"\"}', 3, '110534212', 'uknim', '2025-11-24 01:21:49', '2025-11-24 01:21:49', NULL),
(2, '測試測試測試', '[\"110534206\",\"110534226\"]', 'scottli', 'uploads/team_apply/apply_110534226_1763953209.jpg', '{\"group_id\":\"1\",\"comment\":\"\"}', 3, '110534226', 'uknim', '2025-11-24 11:00:19', '2025-11-24 11:00:19', NULL),
(3, '測試測試測試', '[\"110534206\",\"110534226\"]', 'scottli', 'uploads/team_apply/apply_110534226_1763953547.jpg', '{\"group_id\":\"1\",\"comment\":\"\"}', 3, '110534226', 'uknim', '2025-11-24 11:06:02', '2025-11-24 11:06:02', NULL),
(4, '測試測試測試', '[\"110534210\",\"110534212\"]', 'yang', 'uploads/team_apply/apply_110534212_1763961247.jpg', '{\"group_id\":\"2\",\"comment\":\"\"}', 3, '110534212', 'uknim', '2025-11-24 13:14:18', '2025-11-24 13:14:18', NULL),
(5, '測試測試測試', '[\"110534206\",\"110534226\"]', 'scottli', 'uploads/team_apply/apply_110534226_1764147406.jpg', '{\"group_id\":\"1\",\"comment\":\"\"}', 3, '110534226', 'uknim', '2025-11-26 16:57:27', '2025-11-26 16:57:27', NULL),
(6, '測試測試測試', '[\"110534210\",\"110534212\"]', 'yang', 'uploads/team_apply/apply_110534212_1764147630.jpg', '{\"group_id\":\"2\",\"comment\":\"\"}', 3, '110534212', 'uknim', '2025-11-26 17:00:42', '2025-11-26 17:00:42', NULL),
(7, '123', '[\"110534206\",\"110534226\"]', 'scottli', 'uploads/team_apply/apply_110534226_1764310507.jpg', '{\"group_id\":\"1\",\"comment\":\"\"}', 3, '110534226', 'uknim', '2025-11-28 14:15:16', '2025-11-28 14:15:16', NULL),
(8, 'test', '[\"110534104\",\"110534101\"]', 'beckchou', 'uploads/team_apply/apply_110534101_1764931441.jpg', '{\"group_id\":\"1\",\"comment\":\"\"}', 1, '110534101', 'uknim', NULL, '2025-12-10 14:09:51', 'sdf'),
(9, '123', '[\"110534229\",\"110534118\"]', 'beckchou', 'uploads/team_apply/apply_110534118_1765430014.jpg', '{\"group_id\":\"1\",\"comment\":\"\"}', 1, '110534118', NULL, NULL, '2025-12-11 13:13:34', NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `teamdata`
--

CREATE TABLE `teamdata` (
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `group_ID` int(11) DEFAULT NULL COMMENT '類組',
  `team_project_name` varchar(25) DEFAULT NULL COMMENT '專題名稱',
  `cohort_ID` int(11) DEFAULT NULL COMMENT '屆別',
  `team_status` tinyint(1) NOT NULL COMMENT '狀態',
  `team_update_d` datetime DEFAULT NULL COMMENT '更新時間',
  `team_url` varchar(255) NOT NULL COMMENT '申請檔案'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='團隊';

--
-- 傾印資料表的資料 `teamdata`
--

INSERT INTO `teamdata` (`team_ID`, `group_ID`, `team_project_name`, `cohort_ID`, `team_status`, `team_update_d`, `team_url`) VALUES
(1, 1, '專題日總彙', 3, 1, '2025-11-06 12:14:02', ''),
(2, 2, '微旅日記', 3, 1, '2025-11-06 12:14:02', ''),
(3, 1, '昊德經絡', 2, 0, '2025-11-06 12:14:02', ''),
(4, 1, '招生系統', 3, 1, '2025-11-06 12:14:02', ''),
(5, 2, '童話事故', 3, 1, '2025-11-06 12:14:02', ''),
(6, 1, '智慧實習平台', 3, 1, '2025-09-11 04:14:35', ''),
(7, 2, '數位導盲工具', 3, 1, '2025-12-11 09:48:52', ''),
(8, 1, '商務網站進銷存系統', 3, 1, '2025-12-11 09:48:52', ''),
(9, 2, '社畜胖網路軍機模型專賣店', 3, 1, '2025-12-11 09:50:06', '');

-- --------------------------------------------------------

--
-- 資料表結構 `teammember`
--

CREATE TABLE `teammember` (
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `team_u_ID` varchar(25) NOT NULL COMMENT '使用者',
  `tm_status` tinyint(1) DEFAULT NULL COMMENT '狀態',
  `tm_updated_d` datetime DEFAULT NULL COMMENT '更新時間',
  `tm_url` varchar(255) DEFAULT NULL COMMENT '異動檔案提交'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='團隊成員';

--
-- 傾印資料表的資料 `teammember`
--

INSERT INTO `teammember` (`team_ID`, `team_u_ID`, `tm_status`, `tm_updated_d`, `tm_url`) VALUES
(1, '110534205', 1, '2025-11-06 12:17:00', NULL),
(1, '110534215', 1, '2025-11-06 12:17:00', NULL),
(1, '110534221', 1, '2025-11-06 12:17:00', NULL),
(1, '110534231', 1, '2025-11-06 12:17:00', NULL),
(1, 'beckchou', 1, '2025-11-06 12:17:00', NULL),
(1, 'toshiko', 1, '2026-01-02 15:50:49', NULL),
(1, 'uknim', 1, '2025-12-10 17:55:18', NULL),
(2, '110534207', 1, '2025-11-06 12:17:00', NULL),
(2, '110534216', 1, '2025-11-06 12:17:00', NULL),
(2, '110534217', 1, '2025-11-06 12:17:00', NULL),
(2, 'toshiko', 1, '2025-11-06 12:17:00', NULL),
(2, 'uknim', 1, '2025-12-10 17:55:36', NULL),
(3, '109534201', 1, '2025-11-06 12:17:00', NULL),
(3, '109534206', 1, '2025-11-06 12:17:00', NULL),
(3, '109534207', 1, '2025-11-06 12:17:00', NULL),
(3, 'beckchou', 1, '2025-11-06 12:17:00', NULL),
(3, 'uknim', 1, '2025-12-10 17:55:49', NULL),
(4, '110511114', 1, '2025-11-06 12:17:00', NULL),
(4, '110534201', 1, '2025-11-06 12:17:00', NULL),
(4, '110534225', 1, '2025-11-06 12:17:00', NULL),
(4, '110534236', 1, '2025-11-06 12:17:00', NULL),
(4, 'scottli', 1, '2025-11-06 12:17:00', NULL),
(4, 'uknim', 1, '2025-12-10 17:55:36', NULL),
(5, '110534136', 1, '2025-11-06 12:17:00', NULL),
(5, '110534202', 1, '2025-11-06 12:17:00', NULL),
(5, '110534213', 1, '2025-11-06 12:17:00', NULL),
(5, 'uknim', 1, '2025-12-10 17:56:10', NULL),
(5, 'yang', 1, '2025-11-06 12:17:00', NULL),
(6, '110534206', 1, '2025-11-06 12:17:00', NULL),
(6, '110534226', 1, '2025-11-06 12:17:00', NULL),
(6, '110534235', 1, '2025-11-06 12:17:00', NULL),
(6, '110534244', 1, '2025-11-06 12:17:00', NULL),
(6, 'scottli', 1, '2025-11-06 12:17:00', NULL),
(6, 'uknim', 1, '2025-12-10 17:56:02', NULL),
(7, '110534115', 1, '2025-12-11 10:04:59', NULL),
(7, '110534116', 1, '2025-12-11 10:04:59', NULL),
(7, '110534119', 1, '2025-12-11 09:57:01', NULL),
(7, '110534137', 1, '2025-12-11 09:57:01', NULL),
(7, 'wennie', 1, '2025-12-11 10:17:27', NULL),
(8, '110534132', 1, '2025-12-11 10:05:30', NULL),
(8, '110534134', 1, '2025-12-11 10:05:30', NULL),
(8, '110534138', 1, '2025-12-11 10:14:39', NULL),
(8, 'stanleyh', 1, '2025-12-11 10:17:27', NULL),
(9, '110534105', 1, '2025-12-11 10:17:27', NULL),
(9, '110534110', 1, '2025-12-11 10:17:27', NULL),
(9, '110534113', 1, '2025-12-11 10:17:27', NULL),
(9, '110534123', 1, '2025-12-18 10:17:27', NULL),
(9, 'claire', 1, '2025-12-11 10:17:27', NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `timedata`
--

CREATE TABLE `timedata` (
  `time_ID` int(11) NOT NULL COMMENT '流水號',
  `tinforma_ID` int(11) NOT NULL COMMENT '資訊ID（對應 timeinformadata）',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `time_name` varchar(200) NOT NULL COMMENT '標題',
  `type_ID` int(11) DEFAULT NULL COMMENT '分類ID',
  `time_start_d` datetime NOT NULL COMMENT '開始時間',
  `time_end_d` datetime NOT NULL COMMENT '結束時間',
  `sort_no` int(11) DEFAULT NULL COMMENT '手動排序(組次)，可空；越小越前'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='時程表目標t';

--
-- 傾印資料表的資料 `timedata`
--

INSERT INTO `timedata` (`time_ID`, `tinforma_ID`, `team_ID`, `time_name`, `type_ID`, `time_start_d`, `time_end_d`, `sort_no`) VALUES
(561, 1, 2, '', NULL, '2025-12-09 10:30:00', '2025-12-09 10:50:00', 1),
(562, 1, 4, '', NULL, '2025-12-09 11:00:00', '2025-12-09 11:20:00', 2),
(563, 1, 1, '', NULL, '2025-12-09 11:30:00', '2025-12-09 11:50:00', 3),
(564, 1, 5, '', NULL, '2025-12-09 12:00:00', '2025-12-09 12:20:00', 4),
(565, 1, 6, '', NULL, '2025-12-09 12:30:00', '2025-12-09 12:50:00', 5),
(566, 2, 1, '', NULL, '2025-12-10 10:30:00', '2025-12-10 10:50:00', 1),
(567, 2, 2, '', NULL, '2025-12-10 11:00:00', '2025-12-10 11:20:00', 2),
(568, 2, 4, '', NULL, '2025-12-10 11:30:00', '2025-12-10 11:50:00', 3),
(569, 2, 5, '', NULL, '2025-12-10 12:00:00', '2025-12-10 12:20:00', 4),
(570, 2, 6, '', NULL, '2025-12-10 12:30:00', '2025-12-10 12:50:00', 5);

-- --------------------------------------------------------

--
-- 資料表結構 `timeinformadata`
--

CREATE TABLE `timeinformadata` (
  `tinforma_ID` int(11) NOT NULL COMMENT '流水號',
  `tinforma_title` varchar(255) DEFAULT NULL COMMENT '時程表標題',
  `tinforma_content` varchar(255) NOT NULL COMMENT '包含場次準備、上台報告說明、午餐時間、中場休息',
  `tinforma_create_d` datetime NOT NULL DEFAULT current_timestamp() COMMENT '建立時間',
  `tinforma_update_d` datetime DEFAULT NULL ON UPDATE current_timestamp() COMMENT '最後更新時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='時程表資訊';

--
-- 傾印資料表的資料 `timeinformadata`
--

INSERT INTO `timeinformadata` (`tinforma_ID`, `tinforma_title`, `tinforma_content`, `tinforma_create_d`, `tinforma_update_d`) VALUES
(1, NULL, '', '2025-12-08 15:54:27', NULL),
(2, '1', '[]', '2025-12-10 10:17:23', NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `typedata`
--

CREATE TABLE `typedata` (
  `type_ID` int(11) NOT NULL COMMENT '主鍵',
  `type_value` varchar(50) NOT NULL COMMENT '名稱（例：期中、期末、一般、公告、通知）',
  `type_status` tinyint(1) NOT NULL COMMENT '狀態',
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
  `u_img` varchar(255) DEFAULT NULL COMMENT '頭貼路徑/URL',
  `u_status` tinyint(1) NOT NULL COMMENT '狀態',
  `u_update_d` datetime DEFAULT NULL COMMENT '最後修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='使用者';

--
-- 傾印資料表的資料 `userdata`
--

INSERT INTO `userdata` (`u_ID`, `u_password`, `u_name`, `u_gmail`, `u_profile`, `u_img`, `u_status`, `u_update_d`) VALUES
('108534140', '108534140', '徐莉婷', '108534140@stu.ukn.edu.tw', NULL, NULL, 3, NULL),
('109534201', '109534201', '林恩宇', '109534201@stu.ukn.edu.tw', '我站在雲林', NULL, 3, '2025-11-06 11:28:35'),
('109534206', '109534206', '蓁蓁咪', '109534206@stu.ukn.edu.tw', '早上沒事，晚上台中市', NULL, 3, '2025-11-06 11:28:35'),
('109534207', '109534207', '書嫻桑', '109534207@stu.ukn.edu.tw', '', NULL, 3, '2025-11-06 11:28:35'),
('110511114', '110511114', '陳珈宣', '110511114@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534101', '110534101', '林宜伶', '110534101@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534104', '110534104', '葉芃孝', '110534104@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534105', '110534105', '顏劭沂', '110534105@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534107', '110534107', '唐佳臻', '110534107@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534108', '110534108', '林妍妡', '110534108@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534109', '110534109', '蕭鈺萱', '110534109@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534110', '110534110', '陳宥瑋', '110534110@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534113', '110534113', '林永欽', '110534113@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534114', '110534114', '楊勝維', '110534114@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534115', '110534115', '郭孟璇', '110534115@stu.ukn.edu.tw', NULL, NULL, 1, '2025-12-11 09:57:57'),
('110534116', '110534116', '劉承昀', '110534116@stu.ukn.edu.tw', NULL, NULL, 1, NULL),
('110534118', '110534118', '潘星穎', '110534118@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534119', '110534119', '伏詠琳', '110534119@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534120', '110534120', '石盛文', '110534120@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534123', '110534123', '楊翔安', '110534123@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534125', '110534125', '王育圻', '110534125@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534132', '110534132', '郭芷妍', '110534132@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534133', '110534133', '鍾雨凝', '110534133@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534134', '110534134', '陳　湛', '110534134@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534136', '110534136', '張建翊', '110534136@stu.ukn.edu.tw', NULL, NULL, 1, NULL),
('110534137', '110534137', '張家禎', '110534137@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534138', '110534138', '郭晉銘', '110534138@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534145', '110534145', '黃冠錡', '110534145@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:19'),
('110534201', '110534201', '黃堃巽', '110534201@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534202', '110534202', '賴逢尼', '110534202@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534205', '110534205', '邱芷彤', '110534205@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534206', '110534206', '尤思婷', '110534206@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534207', '110534207', '蔡沁妤', '110534207@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534209', '110534209', '林奕璁', '110534209@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534210', '110534210', '汪俊成', '110534210@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534211', '110534211', '陳思維', '110534211@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534212', '110534212', '賴定宏', '110534212@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534213', '110534213', '許登瑞', '110534213@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534215', '110534215', '張莉翎', '110534215@stu.ukn.edu.tw', '嗨莉莉莉', 'u_img_110534215_1763675534.jpg', 1, '2025-11-06 11:28:35'),
('110534216', '110534216', '陳淯玲', '110534216@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534217', '110534217', '陳省恩', '110534217@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534221', '110534221', '羅勻辰', '110534221@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534224', '110534224', '蔡承達', '110534224@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534225', '110534225', '由世全', '110534225@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534226', '110534226', '林圻恩', '110534226@stu.ukn.edu.tw', '竟佔', NULL, 1, NULL),
('110534228', '110534228', '謝秉哲', '110534228@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-21 17:15:56'),
('110534229', '110534229', '吳嘉緯', '110534229@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-21 17:15:56'),
('110534231', '110534231', '凱饒婷', '110534231@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534232', '110534232', '蔡愷慈', '110534232@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-21 17:15:56'),
('110534233', '110534233', '李玉馨', '110534233@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-21 17:15:56'),
('110534235', '110534235', '周佳儀', '110534235@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534236', '110534236', '林奕廷', '110534236@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534237', '110534237', '蔡宏恩', '110534237@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-21 17:15:56'),
('110534238', '110534238', '黃哲渝', '110534238@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-21 17:15:56'),
('110534242', '110534242', '郭紘丞', '110534242@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('110534244', '110534244', '馬嫚蔆', '110534244@stu.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('beckchou', '1234', '建宇貝殼', 'beckchou@g.ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('claire', '1234', '益西曲珍', 'amy0718@ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:32'),
('jack', '1234', '李柏松', 'jacklee521@ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:32'),
('scottli', '1234', '李岳倫', 'scottli@ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:32'),
('stanleyh', '1234', '謝樹明', 'stanleyh@ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:32'),
('system', 'uknsystempro', '系統', '', NULL, NULL, 1, '2025-11-06 11:28:35'),
('test', '$2y$10$ZYbBp9d14zesO', 'test', '', NULL, NULL, 1, '2025-12-09 15:03:27'),
('test1', 'test1', 'test1', '', NULL, NULL, 3, NULL),
('test2', 'test2', 'test2', 'test2@stu.ukn.edu.tw', NULL, NULL, 1, NULL),
('test3', 'test3', '2', 'test3@stu.ukn.edu.tw', NULL, NULL, 2, NULL),
('toshiko', '1234', '嚴竹華', 'echoyan@ukn.edu.tw', NULL, NULL, 1, '2025-11-06 11:28:35'),
('uknim', '1234', '科辦', '', NULL, NULL, 1, '2025-11-06 11:28:35'),
('wennie', '1234', '朱錦文', 'wennie@ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:32'),
('yang', '1234', '楊景琦', 'yang@ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:32'),
('yfpeng', '1234', '彭賓鈺', 'yfpeng@ukn.edu.tw', NULL, NULL, 1, '2025-11-24 09:58:32');

-- --------------------------------------------------------

--
-- 資料表結構 `userrolesdata`
--

CREATE TABLE `userrolesdata` (
  `ur_u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `role_ID` int(11) NOT NULL COMMENT '角色ID',
  `user_role_status` tinyint(1) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='使用者角色關聯';

--
-- 傾印資料表的資料 `userrolesdata`
--

INSERT INTO `userrolesdata` (`ur_u_ID`, `role_ID`, `user_role_status`) VALUES
('109534201', 6, 1),
('109534206', 6, 1),
('109534207', 6, 1),
('110511114', 6, 1),
('110534101', 6, 1),
('110534104', 6, 1),
('110534105', 6, 1),
('110534107', 6, 1),
('110534108', 6, 1),
('110534109', 6, 1),
('110534110', 6, 1),
('110534113', 6, 1),
('110534114', 6, 1),
('110534115', 6, 1),
('110534116', 6, 1),
('110534118', 6, 1),
('110534119', 6, 1),
('110534120', 6, 1),
('110534123', 6, 1),
('110534125', 6, 1),
('110534132', 6, 1),
('110534133', 6, 1),
('110534134', 6, 1),
('110534136', 6, 1),
('110534137', 6, 1),
('110534138', 6, 1),
('110534145', 6, 1),
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
('110534228', 6, 1),
('110534229', 6, 1),
('110534231', 6, 1),
('110534232', 6, 1),
('110534233', 6, 1),
('110534235', 6, 1),
('110534236', 6, 1),
('110534237', 6, 1),
('110534238', 6, 1),
('110534242', 6, 1),
('110534244', 6, 1),
('beckchou', 3, 1),
('beckchou', 4, 1),
('claire', 3, 1),
('claire', 4, 1),
('jack', 4, 1),
('scottli', 4, 1),
('stanleyh', 4, 1),
('system', 0, 1),
('test1', 6, 1),
('test2', 5, 1),
('test3', 4, 1),
('toshiko', 1, 1),
('toshiko', 3, 1),
('toshiko', 4, 1),
('uknim', 1, 1),
('uknim', 2, 1),
('uknim', 3, 1),
('uknim', 4, 1),
('uknim', 6, 1),
('wennie', 4, 1),
('yang', 4, 1),
('yfpeng', 4, 1);

-- --------------------------------------------------------

--
-- 資料表結構 `workdata`
--

CREATE TABLE `workdata` (
  `work_ID` int(11) NOT NULL,
  `work_title` varchar(100) NOT NULL COMMENT '標題',
  `work_content` varchar(255) DEFAULT NULL COMMENT '內容',
  `work_u_ID` varchar(25) NOT NULL COMMENT '提交者',
  `req_ID` int(11) DEFAULT NULL COMMENT '基本需求ID',
  `ms_ID` int(11) DEFAULT NULL COMMENT '里程碑ID',
  `task_ID` int(11) DEFAULT NULL COMMENT '任務ID',
  `work_status` tinyint(1) NOT NULL COMMENT '狀態',
  `comment` varchar(200) DEFAULT NULL COMMENT '團隊其他人留言',
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
-- 資料表索引 `grouptypedata`
--
ALTER TABLE `grouptypedata`
  ADD PRIMARY KEY (`type_ID`),
  ADD KEY `fk_grouptype_group` (`group_ID`),
  ADD KEY `fk_grouptype_status` (`type_status`);

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
  ADD KEY `idx_prv_user` (`pe_u_ID`),
  ADD KEY `fk_pereview_user` (`petarget_u_ID`);

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
  ADD KEY `fk_suggest_sf` (`sf_ID`);

--
-- 資料表索引 `suggestfrom`
--
ALTER TABLE `suggestfrom`
  ADD PRIMARY KEY (`sf_ID`),
  ADD KEY `fk_sf_user` (`sf_u_ID`);

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
-- 資料表索引 `teacherteamlimit`
--
ALTER TABLE `teacherteamlimit`
  ADD PRIMARY KEY (`ttl_ID`),
  ADD UNIQUE KEY `uk_teacher_cohort` (`ttl_u_ID`,`cohort_ID`),
  ADD KEY `fk_ttl_cohort` (`cohort_ID`);

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
  MODIFY `doc_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `docsubdata`
--
ALTER TABLE `docsubdata`
  MODIFY `sub_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '申請ID', AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `enrollmentdata`
--
ALTER TABLE `enrollmentdata`
  MODIFY `enroll_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `filedata`
--
ALTER TABLE `filedata`
  MODIFY `file_ID` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `grouptypedata`
--
ALTER TABLE `grouptypedata`
  MODIFY `type_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '細項類組ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `milesdata`
--
ALTER TABLE `milesdata`
  MODIFY `ms_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '里程碑ID', AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `msgdata`
--
ALTER TABLE `msgdata`
  MODIFY `msg_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `pereviewdata`
--
ALTER TABLE `pereviewdata`
  MODIFY `peer_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '互評紀錄ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `perioddata`
--
ALTER TABLE `perioddata`
  MODIFY `period_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `petargetdata`
--
ALTER TABLE `petargetdata`
  MODIFY `pe_target_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=5;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `projectdata`
--
ALTER TABLE `projectdata`
  MODIFY `pro_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '專題ID', AUTO_INCREMENT=3;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `prosubdata`
--
ALTER TABLE `prosubdata`
  MODIFY `prosub_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `reprogressdata`
--
ALTER TABLE `reprogressdata`
  MODIFY `rp_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '紀錄ID', AUTO_INCREMENT=5;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `requirementdata`
--
ALTER TABLE `requirementdata`
  MODIFY `req_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '需求ID', AUTO_INCREMENT=17;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `suggest`
--
ALTER TABLE `suggest`
  MODIFY `suggest_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '主鍵', AUTO_INCREMENT=7;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `suggestfrom`
--
ALTER TABLE `suggestfrom`
  MODIFY `sf_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `taskdata`
--
ALTER TABLE `taskdata`
  MODIFY `task_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '任務ID', AUTO_INCREMENT=8;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `teacherteamlimit`
--
ALTER TABLE `teacherteamlimit`
  MODIFY `ttl_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=3;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `teamapply`
--
ALTER TABLE `teamapply`
  MODIFY `tap_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=10;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `teamdata`
--
ALTER TABLE `teamdata`
  MODIFY `team_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '團隊ID', AUTO_INCREMENT=10;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `timedata`
--
ALTER TABLE `timedata`
  MODIFY `time_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=571;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `timeinformadata`
--
ALTER TABLE `timeinformadata`
  MODIFY `tinforma_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '流水號', AUTO_INCREMENT=3;

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
-- 資料表的限制式 `grouptypedata`
--
ALTER TABLE `grouptypedata`
  ADD CONSTRAINT `fk_grouptype_group` FOREIGN KEY (`group_ID`) REFERENCES `groupdata` (`group_ID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_grouptype_status` FOREIGN KEY (`type_status`) REFERENCES `statusdata` (`status_ID`) ON UPDATE CASCADE;

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
  ADD CONSTRAINT `fk_pereview_user` FOREIGN KEY (`petarget_u_ID`) REFERENCES `userdata` (`u_ID`) ON DELETE SET NULL ON UPDATE CASCADE,
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
  ADD CONSTRAINT `fk_suggest_sf` FOREIGN KEY (`sf_ID`) REFERENCES `suggestfrom` (`sf_ID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 資料表的限制式 `suggestfrom`
--
ALTER TABLE `suggestfrom`
  ADD CONSTRAINT `fk_sf_user` FOREIGN KEY (`sf_u_ID`) REFERENCES `userdata` (`u_ID`) ON UPDATE CASCADE;

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
-- 資料表的限制式 `teacherteamlimit`
--
ALTER TABLE `teacherteamlimit`
  ADD CONSTRAINT `fk_ttl_cohort` FOREIGN KEY (`cohort_ID`) REFERENCES `cohortdata` (`cohort_ID`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ttl_teacher` FOREIGN KEY (`ttl_u_ID`) REFERENCES `userdata` (`u_ID`) ON UPDATE CASCADE;

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
