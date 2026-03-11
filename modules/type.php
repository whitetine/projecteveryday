<?php 
session_start();
require '../includes/pdo.php';
$p = $_POST;
switch ($_GET["do"]) {
    // T1117分類管理=>type.php
    case "get_type_all":
        echo json_encode(fetchAll(query("SELECT * FROM `typedata` ORDER BY type_status DESC , type_created_d;")));
        break;
    case "type_new_submit":
        query("INSERT INTO `typedata` (`type_ID`, `type_value`, `type_status`, `type_created_d`) VALUES (NULL, '{$p['type_name']}', '1', current_timestamp());");
        break;
    case "type_stop":
        query("UPDATE `typedata` SET `type_status` = '{$p['type_status']}' WHERE `typedata`.`type_ID` = '{$p['type_ID']}';");
        break;
    }