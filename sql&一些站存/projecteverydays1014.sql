-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主機： 127.0.0.1
-- 產生時間： 2025-10-14 08:58:56
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
  `u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `role_ID` int(11) NOT NULL COMMENT '使用角色ID',
  `ip_address` varchar(45) NOT NULL COMMENT '使用者IP',
  `access_time` datetime NOT NULL COMMENT '訪問時間',
  `access_type` varchar(50) NOT NULL COMMENT '類型',
  `page_url` text NOT NULL COMMENT '頁面路徑',
  `sucess` tinyint(1) NOT NULL COMMENT '是否成功'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登入、使用紀錄資料';

-- --------------------------------------------------------

--
-- 資料表結構 `actionlogs`
--

CREATE TABLE `actionlogs` (
  `action_ID` int(11) NOT NULL COMMENT '行為ID',
  `u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `role_ID` int(11) NOT NULL COMMENT '使用者角色ID',
  `action_type` varchar(100) NOT NULL COMMENT '動作類型',
  `target_table` varchar(100) NOT NULL COMMENT '動用資料表',
  `target_ID` int(11) NOT NULL COMMENT '動用資料表的ID',
  `action_description` text NOT NULL COMMENT '描述動作',
  `action_time` datetime NOT NULL COMMENT '動作時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作行為紀錄資料';

-- --------------------------------------------------------

--
-- 資料表結構 `applydata`
--

CREATE TABLE `applydata` (
  `apply_ID` int(11) NOT NULL COMMENT '申請ID',
  `file_ID` int(11) NOT NULL COMMENT '文件ID',
  `apply_status` int(11) NOT NULL COMMENT '文件狀態',
  `apply_a_u_ID` varchar(25) NOT NULL COMMENT '申請人',
  `apply_b_u_ID` varchar(25) DEFAULT NULL COMMENT '審核人',
  `apply_created_d` datetime NOT NULL COMMENT '申請時間',
  `approved_d` datetime DEFAULT NULL COMMENT '通過時間',
  `apply_other` text DEFAULT NULL COMMENT '其他申請詳細資料',
  `apply_url` text DEFAULT NULL COMMENT '文圖檔位置'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='申請紀錄資料';

-- --------------------------------------------------------

--
-- 資料表結構 `belldata`
--

CREATE TABLE `belldata` (
  `bell_ID` int(11) NOT NULL COMMENT '通知ID',
  `bell_title` text NOT NULL COMMENT '通知標題',
  `bell_content` text DEFAULT NULL COMMENT '通知內容',
  `bell_type` enum('applydata','classdata','filedata','groupdata','peerreview','progressdata','projectsdata','reviewperiods','roledata','statusdata','taskdata','teamdata','teammember','tpedit','userdata','userrolesdata','workdata','belldata') DEFAULT NULL COMMENT '通知種類',
  `source_ID` varchar(100) DEFAULT NULL COMMENT '其他資料表ID',
  `u_ID` varchar(25) NOT NULL COMMENT '通知創建人',
  `bell_files` text DEFAULT NULL COMMENT '通知文件',
  `bell_images` text DEFAULT NULL COMMENT '通知圖片',
  `link_url` text DEFAULT NULL COMMENT '路徑',
  `payload` text DEFAULT NULL COMMENT '彈性欄位',
  `bell_status` int(11) NOT NULL COMMENT '狀態',
  `bell_start_d` int(11) NOT NULL COMMENT '發布時間',
  `bell_end_d` int(11) DEFAULT NULL COMMENT '結束時間',
  `bell_update_d` int(11) NOT NULL COMMENT '最後修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `classdata`
--

CREATE TABLE `classdata` (
  `class_ID` int(11) NOT NULL COMMENT '班級ID',
  `class_name` varchar(10) NOT NULL COMMENT '班級名稱'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='班級資料';

--
-- 傾印資料表的資料 `classdata`
--

INSERT INTO `classdata` (`class_ID`, `class_name`) VALUES
(1, '忠'),
(2, '孝');

-- --------------------------------------------------------

--
-- 資料表結構 `filedata`
--

CREATE TABLE `filedata` (
  `file_ID` int(11) NOT NULL COMMENT '文件ID',
  `file_name` varchar(50) NOT NULL COMMENT '文件名稱',
  `file_url` text NOT NULL COMMENT '文件位置',
  `file_other` text DEFAULT NULL COMMENT '範例格式',
  `is_top` tinyint(1) DEFAULT NULL COMMENT '文件置頂',
  `file_update_d` datetime NOT NULL COMMENT '文件最後修改時間',
  `file_status` int(11) NOT NULL COMMENT '文件狀態',
  `file_end_d` datetime DEFAULT NULL COMMENT '截止日期'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='範例文件資料';

--
-- 傾印資料表的資料 `filedata`
--

INSERT INTO `filedata` (`file_ID`, `file_name`, `file_url`, `file_other`, `is_top`, `file_update_d`, `file_status`, `file_end_d`) VALUES
(1, '指導教師同意暨參賽切結書', 'templates/tpl_20251014_074840_5df5a4.pdf', NULL, 0, '2025-10-14 13:48:40', 1, NULL),
(2, '指導教師同意暨參賽切結書', 'templates/tpl_20251014_074854_5c8d92.pdf', NULL, 0, '2025-10-14 13:48:54', 1, NULL),
(3, '113年度康寧學校財團法人康寧大學國際體驗學習計畫(公告版)', 'templates/tpl_20251014_084340_c4aad0.pdf', NULL, 0, '2025-10-14 14:43:40', 1, NULL),
(4, '艾訊實習成果報告PPT', 'templates/tpl_20251014_084835_a4de60.pdf', NULL, 0, '2025-10-14 14:48:35', 1, NULL);

-- --------------------------------------------------------

--
-- 資料表結構 `groupdata`
--

CREATE TABLE `groupdata` (
  `group_ID` int(11) NOT NULL COMMENT '類組ID',
  `group_name` varchar(25) NOT NULL COMMENT '類組名稱',
  `group_status` int(11) NOT NULL COMMENT '類組狀態',
  `group_created_d` datetime NOT NULL COMMENT '異動時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='類組資料';

--
-- 傾印資料表的資料 `groupdata`
--

INSERT INTO `groupdata` (`group_ID`, `group_name`, `group_status`, `group_created_d`) VALUES
(1, '系統組', 1, '2025-07-17 03:56:43'),
(2, '商務組', 1, '2025-07-17 03:56:43'),
(3, '測試組', 0, '2025-08-11 15:32:58'),
(4, 'ukn', 1, '2025-09-17 14:46:36');

-- --------------------------------------------------------

--
-- 資料表結構 `notifydata`
--

CREATE TABLE `notifydata` (
  `notify_ID` int(11) NOT NULL COMMENT '公告ID',
  `notify_title` text NOT NULL COMMENT '公告標題',
  `notify_content` text DEFAULT NULL COMMENT '公告內容',
  `notify_file` text DEFAULT NULL COMMENT '公告檔案',
  `notify_images` text DEFAULT NULL COMMENT '公告圖片',
  `notify_a_u_ID` varchar(25) NOT NULL COMMENT '創建人',
  `notify_b_u_ID` varchar(25) NOT NULL COMMENT '最後修改人',
  `notify_status` int(11) NOT NULL COMMENT '公告狀態',
  `notify_start_d` datetime NOT NULL COMMENT '發布時間',
  `notify_end_d` datetime DEFAULT NULL COMMENT '結束時間',
  `notify_update_d` datetime NOT NULL COMMENT '最後修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 資料表結構 `peerreview`
--

CREATE TABLE `peerreview` (
  `review_ID` int(11) NOT NULL COMMENT '互評紀錄ID',
  `period_ID` int(11) NOT NULL COMMENT '互評時段',
  `review_a_u_ID` varchar(25) NOT NULL COMMENT '評分者ID',
  `review_b_u_ID` varchar(25) NOT NULL COMMENT '被評分者ID',
  `score` int(11) NOT NULL COMMENT '星等評分',
  `review_comment` text DEFAULT NULL COMMENT '評論',
  `review_created_d` datetime NOT NULL COMMENT '評論時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='互評紀錄資料';

-- --------------------------------------------------------

--
-- 資料表結構 `periodsdata`
--

CREATE TABLE `periodsdata` (
  `period_ID` int(11) NOT NULL COMMENT '時段ID',
  `period_start_d` date NOT NULL COMMENT '開放日',
  `period_end_d` date NOT NULL COMMENT '截止日',
  `period_title` varchar(50) NOT NULL COMMENT '標題',
  `is_active` tinyint(1) NOT NULL COMMENT '是否開放',
  `period_created_d` datetime NOT NULL COMMENT '創建時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='時段設定資料';

--
-- 傾印資料表的資料 `periodsdata`
--

INSERT INTO `periodsdata` (`period_ID`, `period_start_d`, `period_end_d`, `period_title`, `is_active`, `period_created_d`) VALUES
(1, '2025-06-01', '2025-06-30', '六月', 1, '2025-07-17 09:51:23'),
(2, '2025-07-01', '2025-07-31', '七月', 1, '2025-07-17 09:51:23'),
(3, '2025-08-01', '2025-08-31', '八月', 1, '2025-07-17 09:51:23'),
(4, '2025-09-01', '2025-09-30', '九月', 1, '2025-07-17 09:51:23'),
(5, '2025-10-01', '2025-10-31', '十月', 0, '2025-07-17 09:51:23');

-- --------------------------------------------------------

--
-- 資料表結構 `progressdata`
--

CREATE TABLE `progressdata` (
  `progress_ID` int(11) NOT NULL COMMENT '進度iD',
  `group_ID` int(11) NOT NULL COMMENT '類組ID',
  `progress_title` varchar(30) NOT NULL COMMENT '進度標題',
  `progress_describe` text DEFAULT NULL COMMENT '進度描述',
  `progress_count` text DEFAULT NULL COMMENT '進度量化',
  `u_ID` varchar(25) NOT NULL COMMENT '使用者',
  `progress_status` int(11) NOT NULL COMMENT '進度狀態',
  `progress_created_d` datetime NOT NULL COMMENT '進度建立時間',
  `progress_end_d` datetime DEFAULT NULL COMMENT '須完成期限'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='進度資料';

--
-- 傾印資料表的資料 `progressdata`
--

INSERT INTO `progressdata` (`progress_ID`, `group_ID`, `progress_title`, `progress_describe`, `progress_count`, `u_ID`, `progress_status`, `progress_created_d`, `progress_end_d`) VALUES
(4, 1, '資料表', NULL, '6', 'admin', 1, '2025-07-23 05:08:43', '2025-07-31 05:08:43'),
(5, 1, '使用者(不含訪客)', NULL, '3', 'admin', 1, '2025-07-23 05:08:43', '2025-07-31 05:08:43');

-- --------------------------------------------------------

--
-- 資料表結構 `projectsdata`
--

CREATE TABLE `projectsdata` (
  `projects_ID` int(11) NOT NULL COMMENT '專題ID',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `projects_describe` text NOT NULL COMMENT '專題簡介',
  `projects_img` text DEFAULT NULL COMMENT '海報檔案',
  `projects_update_d` datetime NOT NULL COMMENT '更新時間',
  `projects_status` int(11) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='歷屆專題資料';

--
-- 傾印資料表的資料 `projectsdata`
--

INSERT INTO `projectsdata` (`projects_ID`, `team_ID`, `projects_describe`, `projects_img`, `projects_update_d`, `projects_status`) VALUES
(1, 3, '大家好！我們是昊德經絡按摩館官網，希望透過我們的官網，讓大家更了解推拿館的服務內容，可以進行線上預約，選擇適合的按摩師傅與項目。我們還提供3D人體經絡圖及測驗，讓大家更直觀的了解經絡理論與保健方法。對於無法親自到場的顧客也可透過線上諮詢與老闆同步溝通。網站上的教學文章涵蓋各種按摩技巧與經絡知識，希望幫助大家在日常生活中輕鬆保健！', 'https://scontent.ftpe7-4.fna.fbcdn.net/v/t39.30808-6/489650319_1083656887112676_5198401455958101904_n.jpg?_nc_cat=105&ccb=1-7&_nc_sid=127cfc&_nc_ohc=OlEYkBrxVokQ7kNvwE7PIB2&_nc_oc=AdkFMj-Abzjxn3OukyHUiiijaX0Ich29LPWrCdTgM7M8KqaBUSPQ4wsJhWXiNak7HXdOx-PCb5zs4LDbBs-KEMEJ&_nc_zt=23&_nc_ht=scontent.ftpe7-4.fna&_nc_gid=BYw8-R9ad1k1AqEUSE8hXA&oh=00_AfQAH4ZLxwCUNRd1rzBhYHr9sU3c8vTMKrPQV03gRI7J-A&oe=68862EA2', '2025-07-23 04:49:35', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `roledata`
--

CREATE TABLE `roledata` (
  `role_ID` int(11) NOT NULL COMMENT '角色ID',
  `role_name` varchar(25) NOT NULL COMMENT '角色名稱',
  `role_directions` text NOT NULL COMMENT '角色說明',
  `role_created_d` datetime NOT NULL COMMENT '創建時間',
  `role_update_d` datetime NOT NULL COMMENT '最後修改時間',
  `role_status` int(11) NOT NULL COMMENT '角色狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `roledata`
--

INSERT INTO `roledata` (`role_ID`, `role_name`, `role_directions`, `role_created_d`, `role_update_d`, `role_status`) VALUES
(0, '系統', '系統', '2025-10-03 08:52:24', '2025-10-03 08:52:24', 1),
(1, '主任', '主任', '2025-07-17 03:52:46', '2025-07-17 03:52:46', 1),
(2, '科辦', '科辦', '2025-07-17 03:52:46', '2025-07-17 03:52:46', 1),
(3, '班導', '班導', '2025-07-17 03:52:46', '2025-07-17 03:52:46', 1),
(4, '指導老師', '指導老師', '2025-07-17 03:52:46', '2025-07-17 03:52:46', 1),
(5, '學生', '學生', '2025-07-17 03:52:46', '2025-07-17 03:52:46', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `statusdata`
--

CREATE TABLE `statusdata` (
  `status_ID` int(11) NOT NULL COMMENT '狀態ID',
  `status_name` varchar(15) NOT NULL COMMENT '狀態名稱'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='狀態資料';

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
-- 資料表結構 `suggestdata`
--

CREATE TABLE `suggestdata` (
  `suggest_ID` int(11) NOT NULL,
  `u_ID` varchar(25) NOT NULL COMMENT '評論者',
  `team_ID` int(11) NOT NULL COMMENT '被評論團隊',
  `suggest_comment` text NOT NULL COMMENT '評論內容',
  `period_ID` int(11) NOT NULL COMMENT '時段ID',
  `suggest_status` int(11) NOT NULL COMMENT '狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='期中期末建議';

-- --------------------------------------------------------

--
-- 資料表結構 `taskdata`
--

CREATE TABLE `taskdata` (
  `task_ID` int(11) NOT NULL COMMENT '任務ID',
  `u_ID` varchar(25) NOT NULL COMMENT '創立者ID',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `task_value` text NOT NULL COMMENT '任務內容',
  `task_created_d` datetime NOT NULL COMMENT '建立時間',
  `task_end_d` datetime DEFAULT NULL COMMENT '需完成期限',
  `task_update_d` datetime NOT NULL COMMENT '更新時間',
  `task_status` int(11) NOT NULL COMMENT '任務狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任務資料';

--
-- 傾印資料表的資料 `taskdata`
--

INSERT INTO `taskdata` (`task_ID`, `u_ID`, `team_ID`, `task_value`, `task_created_d`, `task_end_d`, `task_update_d`, `task_status`) VALUES
(1, '110534215', 1, '登入畫面', '2025-07-23 05:20:31', '2025-07-31 11:29:57', '2025-07-23 05:20:31', 1),
(2, '110534221', 1, '預覽圖片', '2025-07-23 05:20:31', '2025-07-31 11:29:57', '2025-07-23 05:20:31', 1),
(3, '110534205', 1, '工作列表', '2025-07-23 05:20:31', '2025-07-31 11:29:57', '2025-07-23 05:20:31', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `teamdata`
--

CREATE TABLE `teamdata` (
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `group_ID` int(11) NOT NULL COMMENT '類組ID',
  `team_project_name` varchar(25) NOT NULL COMMENT '團隊專題名稱',
  `team_year` char(5) NOT NULL COMMENT '學年',
  `team_status` int(11) NOT NULL COMMENT '團隊狀態',
  `team_update_d` datetime NOT NULL COMMENT '最後修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='團隊資料';

--
-- 傾印資料表的資料 `teamdata`
--

INSERT INTO `teamdata` (`team_ID`, `group_ID`, `team_project_name`, `team_year`, `team_status`, `team_update_d`) VALUES
(1, 1, '專題管理', '113', 1, '2025-07-17 04:06:56'),
(2, 2, '微旅日記', '113', 1, '2025-07-17 04:06:56'),
(3, 1, '昊德經絡', '112', 0, '2025-07-23 05:02:38'),
(4, 1, '產學合作', '113', 1, '2025-09-11 04:14:35'),
(5, 2, '逢妮娜組', '114', 1, '2025-10-13 09:25:21'),
(6, 3, '測試', '114', 1, '2025-10-13 09:25:21');

-- --------------------------------------------------------

--
-- 資料表結構 `teammember`
--

CREATE TABLE `teammember` (
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `m_update_d` datetime NOT NULL COMMENT '最後修改時間',
  `tm_status` int(11) NOT NULL COMMENT '團隊使用者狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='團隊成員資料';

--
-- 傾印資料表的資料 `teammember`
--

INSERT INTO `teammember` (`team_ID`, `u_ID`, `m_update_d`, `tm_status`) VALUES
(1, '110534205', '2025-07-17 04:51:52', 1),
(1, '110534215', '2025-07-17 04:51:52', 1),
(1, '110534221', '2025-07-17 04:51:52', 1),
(1, '110534231', '2025-07-17 04:51:52', 1),
(1, 'tengteng', '2025-07-17 04:51:52', 1),
(2, '110534207', '2025-07-17 04:51:52', 1),
(2, '110534213', '2025-07-17 04:51:52', 0),
(2, '110534216', '2025-07-17 04:51:52', 1),
(2, '110534217', '2025-07-17 04:51:52', 1),
(2, 'toshiko', '2025-07-17 04:51:52', 1),
(3, '109534201', '2025-09-11 10:14:04', 1),
(3, '109534206', '2025-09-11 10:14:04', 1),
(3, '109534207', '2025-09-11 10:14:04', 1),
(3, 'tengteng', '2025-09-11 10:14:04', 1),
(4, '110511114', '2025-09-11 10:13:05', 1),
(4, '110534201', '2025-09-11 10:13:05', 1),
(4, '110534225', '2025-09-11 10:13:05', 1),
(4, '110534236', '2025-09-11 10:13:05', 1),
(4, 'tengteng', '2025-09-11 10:13:05', 1),
(5, '110534101', '2025-10-13 10:29:33', 1),
(5, '110534102', '2025-10-13 10:29:33', 1),
(5, '110534202', '2025-10-13 10:29:33', 1),
(5, '110534213', '2025-10-13 10:29:33', 1);

-- --------------------------------------------------------

--
-- 資料表結構 `tpedit`
--

CREATE TABLE `tpedit` (
  `tp_ID` int(11) NOT NULL COMMENT '異動ID',
  `progress_ID` int(11) NOT NULL COMMENT '進度ID',
  `task_ID` int(11) NOT NULL COMMENT '任務ID',
  `u_ID` varchar(25) NOT NULL COMMENT '異動者',
  `team_ID` int(11) NOT NULL COMMENT '團隊ID',
  `tp_status` int(11) NOT NULL COMMENT '異動狀態',
  `tp_url` text DEFAULT NULL COMMENT '異動檔案',
  `task_msg` text DEFAULT NULL COMMENT '備註內容',
  `task_edit_d` datetime NOT NULL COMMENT '異動時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='任務進度異動紀錄資料';

-- --------------------------------------------------------

--
-- 資料表結構 `userdata`
--

CREATE TABLE `userdata` (
  `u_ID` varchar(25) NOT NULL COMMENT '帳號',
  `u_password` char(20) NOT NULL COMMENT '密碼',
  `u_name` char(10) NOT NULL COMMENT '名稱',
  `u_gmail` varchar(150) DEFAULT NULL COMMENT '帳號',
  `u_profile` varchar(300) DEFAULT NULL COMMENT '個人檔案(自介)',
  `u_img` text DEFAULT NULL COMMENT ' 頭貼',
  `u_status` int(11) NOT NULL COMMENT '使用者狀態',
  `c_ID` int(11) DEFAULT NULL COMMENT '班級ID',
  `u_update_d` datetime NOT NULL COMMENT '最後修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='使用者資料表';

--
-- 傾印資料表的資料 `userdata`
--

INSERT INTO `userdata` (`u_ID`, `u_password`, `u_name`, `u_gmail`, `u_profile`, `u_img`, `u_status`, `c_ID`, `u_update_d`) VALUES
('109534201', '109534201', '林恩宇', '109534201@stu.ukn.edu.tw', '我站在雲林', 'u_img_109534201_1758097016.jpg', 0, 2, '2025-07-23 04:50:08'),
('109534206', '109534206', '蓁蓁咪', '109534206@stu.ukn.edu.tw', '早上沒事，晚上台中市', 'u_img_109534206_1755065596.png', 0, 2, '2025-07-23 04:50:08'),
('109534207', '109534207', '書桑', '109534207@stu.ukn.edu.tw', '', NULL, 0, 2, '2025-07-23 04:50:08'),
('110511114', '110511114', '王加桑', '110511114@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-09-11 10:02:24'),
('110534101', '110534101', '忠班1', '110534101@stu.ukn.edu.tw', NULL, NULL, 1, 1, '2025-10-08 10:57:37'),
('110534102', '110534102', '忠班2', '110534102@stu.ukn.edu.tw', NULL, NULL, 1, 1, '2025-10-08 10:57:37'),
('110534201', '110534201', '方方土', '110534201@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-09-11 10:02:24'),
('110534202', '110534202', '逢尼', '110534202@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:55:25'),
('110534205', '110534205', '邱桑', '110534205@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-07-17 04:32:54'),
('110534206', '110534206', '尤斯婷', '110534206@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:55:25'),
('110534207', '110534207', '沁桑', '110534207@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-07-17 04:32:54'),
('110534209', '110534209', '一聰', '110534209@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:57:37'),
('110534210', '110534210', '俊成', '110534210@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:57:37'),
('110534211', '110534211', '思維', '110534211@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:57:37'),
('110534212', '110534212', '下巴宏', '110534212@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:57:37'),
('110534213', '110534213', '登桑', '110534213@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-07-17 04:32:54'),
('110534215', '110534215', '莉桑', '110534215@stu.ukn.edu.tw', '嗨莉莉莉', NULL, 1, 2, '2025-07-17 04:32:54'),
('110534216', '110534216', '玲桑', '110534216@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-07-17 04:32:54'),
('110534217', '110534217', '小恩恩桑', '110534217@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-07-17 04:32:54'),
('110534221', '110534221', '羅桑', '110534221@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-07-17 04:37:39'),
('110534224', '110534224', '達達', '110534224@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:57:37'),
('110534225', '110534225', '由桑', '110534225@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-09-11 10:02:24'),
('110534231', '110534231', '凱桑', '110534231@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-07-17 04:32:54'),
('110534235', '110534235', '加一', '110534235@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:57:37'),
('110534236', '110534236', '二廷', '110534236@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-09-11 10:02:24'),
('110534242', '110534242', '宏陳', '110534242@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 10:57:37'),
('110534244', '110534244', '馬馬咩', '110534244@stu.ukn.edu.tw', NULL, NULL, 1, 2, '2025-10-08 11:01:43'),
('beckchou', '5678', '建宇貝殼', 'beckchou@g.ukn.edu.tw\r\n', NULL, NULL, 1, NULL, '2025-10-08 11:01:43'),
('system', 'uknsystempro', '系統', NULL, NULL, NULL, 1, NULL, '2025-10-03 08:53:42'),
('tengteng', '1234', '宗騰騰', 'ttchen@ukn.edu.tw', NULL, NULL, 1, 0, '2025-07-17 04:32:54'),
('toshiko', '1234', '竹華老師', NULL, NULL, NULL, 1, 0, '2025-07-17 04:46:52'),
('uknim', '1234', '科辦', '', '', NULL, 1, 0, '2025-07-17 04:32:54');

-- --------------------------------------------------------

--
-- 資料表結構 `userrolesdata`
--

CREATE TABLE `userrolesdata` (
  `u_ID` varchar(25) NOT NULL COMMENT '使用者ID',
  `role_ID` int(11) NOT NULL COMMENT '角色ID',
  `user_role_status` int(11) NOT NULL COMMENT '使用者角色狀態'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='使用角色資料表';

--
-- 傾印資料表的資料 `userrolesdata`
--

INSERT INTO `userrolesdata` (`u_ID`, `role_ID`, `user_role_status`) VALUES
('109534201', 2, 1),
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
('110534217', 6, 0),
('110534221', 6, 1),
('110534224', 6, 1),
('110534225', 6, 1),
('110534231', 6, 1),
('110534235', 6, 1),
('110534236', 6, 1),
('110534242', 6, 1),
('110534244', 6, 1),
('beckchou', 3, 1),
('system', 0, 1),
('tengteng', 4, 1),
('toshiko', 1, 1),
('toshiko', 4, 1),
('uknim', 2, 1);

-- --------------------------------------------------------

--
-- 資料表結構 `workdata`
--

CREATE TABLE `workdata` (
  `work_ID` int(11) NOT NULL COMMENT '工作提交ID',
  `work_title` text NOT NULL COMMENT '工作標題',
  `work_content` text DEFAULT NULL COMMENT '工作內容',
  `work_url` text DEFAULT NULL COMMENT '工作檔案位置',
  `u_ID` varchar(25) NOT NULL COMMENT '提交者ID',
  `work_status` int(11) NOT NULL COMMENT '工作狀態',
  `comment` text DEFAULT NULL COMMENT '評論、評語',
  `read_time` datetime DEFAULT NULL COMMENT '已閱時間',
  `work_created_d` datetime NOT NULL COMMENT '發布時間',
  `work_update_d` datetime NOT NULL COMMENT '最後修改時間'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工作日誌資料';

--
-- 傾印資料表的資料 `workdata`
--

INSERT INTO `workdata` (`work_ID`, `work_title`, `work_content`, `work_url`, `u_ID`, `work_status`, `comment`, `read_time`, `work_created_d`, `work_update_d`) VALUES
(1, '討論', '討論老師提出的內容', NULL, 'uknim', 3, NULL, NULL, '2025-10-01 10:44:04', '2025-10-01 11:02:59'),
(2, '討論', 'd', NULL, '110534231', 3, NULL, NULL, '2025-10-01 15:58:26', '2025-10-01 15:58:26'),
(3, 'z', 'z', 'file/20251003_141249_uknim_eb3447e4.jpg', 'uknim', 3, NULL, NULL, '2025-10-03 11:48:25', '2025-10-03 14:12:49'),
(4, '', '', NULL, 'uknim', 1, NULL, NULL, '2025-10-07 09:05:42', '2025-10-07 10:12:55');

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `accesslogs`
--
ALTER TABLE `accesslogs`
  ADD PRIMARY KEY (`access_ID`);

--
-- 資料表索引 `actionlogs`
--
ALTER TABLE `actionlogs`
  ADD PRIMARY KEY (`action_ID`);

--
-- 資料表索引 `applydata`
--
ALTER TABLE `applydata`
  ADD PRIMARY KEY (`apply_ID`);

--
-- 資料表索引 `classdata`
--
ALTER TABLE `classdata`
  ADD PRIMARY KEY (`class_ID`);

--
-- 資料表索引 `filedata`
--
ALTER TABLE `filedata`
  ADD PRIMARY KEY (`file_ID`);

--
-- 資料表索引 `groupdata`
--
ALTER TABLE `groupdata`
  ADD PRIMARY KEY (`group_ID`);

--
-- 資料表索引 `peerreview`
--
ALTER TABLE `peerreview`
  ADD PRIMARY KEY (`review_ID`);

--
-- 資料表索引 `periodsdata`
--
ALTER TABLE `periodsdata`
  ADD PRIMARY KEY (`period_ID`);

--
-- 資料表索引 `progressdata`
--
ALTER TABLE `progressdata`
  ADD PRIMARY KEY (`progress_ID`);

--
-- 資料表索引 `projectsdata`
--
ALTER TABLE `projectsdata`
  ADD PRIMARY KEY (`projects_ID`);

--
-- 資料表索引 `roledata`
--
ALTER TABLE `roledata`
  ADD PRIMARY KEY (`role_ID`);

--
-- 資料表索引 `statusdata`
--
ALTER TABLE `statusdata`
  ADD PRIMARY KEY (`status_ID`);

--
-- 資料表索引 `suggestdata`
--
ALTER TABLE `suggestdata`
  ADD PRIMARY KEY (`suggest_ID`);

--
-- 資料表索引 `taskdata`
--
ALTER TABLE `taskdata`
  ADD PRIMARY KEY (`task_ID`);

--
-- 資料表索引 `teamdata`
--
ALTER TABLE `teamdata`
  ADD PRIMARY KEY (`team_ID`);

--
-- 資料表索引 `teammember`
--
ALTER TABLE `teammember`
  ADD PRIMARY KEY (`team_ID`,`u_ID`);

--
-- 資料表索引 `tpedit`
--
ALTER TABLE `tpedit`
  ADD PRIMARY KEY (`tp_ID`);

--
-- 資料表索引 `userdata`
--
ALTER TABLE `userdata`
  ADD PRIMARY KEY (`u_ID`);

--
-- 資料表索引 `userrolesdata`
--
ALTER TABLE `userrolesdata`
  ADD PRIMARY KEY (`u_ID`,`role_ID`);

--
-- 資料表索引 `workdata`
--
ALTER TABLE `workdata`
  ADD PRIMARY KEY (`work_ID`);

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
  MODIFY `action_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '行為ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `applydata`
--
ALTER TABLE `applydata`
  MODIFY `apply_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '申請ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `classdata`
--
ALTER TABLE `classdata`
  MODIFY `class_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '班級ID', AUTO_INCREMENT=3;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `filedata`
--
ALTER TABLE `filedata`
  MODIFY `file_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '文件ID', AUTO_INCREMENT=5;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `groupdata`
--
ALTER TABLE `groupdata`
  MODIFY `group_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '類組ID', AUTO_INCREMENT=5;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `peerreview`
--
ALTER TABLE `peerreview`
  MODIFY `review_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '互評紀錄ID';

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `periodsdata`
--
ALTER TABLE `periodsdata`
  MODIFY `period_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '時段ID', AUTO_INCREMENT=6;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `progressdata`
--
ALTER TABLE `progressdata`
  MODIFY `progress_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '進度iD', AUTO_INCREMENT=6;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `projectsdata`
--
ALTER TABLE `projectsdata`
  MODIFY `projects_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '專題ID', AUTO_INCREMENT=2;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `roledata`
--
ALTER TABLE `roledata`
  MODIFY `role_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '角色ID', AUTO_INCREMENT=9;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `suggestdata`
--
ALTER TABLE `suggestdata`
  MODIFY `suggest_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `taskdata`
--
ALTER TABLE `taskdata`
  MODIFY `task_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '任務ID', AUTO_INCREMENT=4;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `teamdata`
--
ALTER TABLE `teamdata`
  MODIFY `team_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '團隊ID', AUTO_INCREMENT=8;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `workdata`
--
ALTER TABLE `workdata`
  MODIFY `work_ID` int(11) NOT NULL AUTO_INCREMENT COMMENT '工作提交ID', AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
