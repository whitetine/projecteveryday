<?php
/**
 * 專案路徑配置
 * 定義專案根目錄的絕對路徑
 */

// BASE_PATH 指向專案根目錄（config 的上一層）
// 例如：C:\github\projecteveryday 或 C:\xampp\htdocs\projecteveryday
define('BASE_PATH', realpath(__DIR__ . '/..'));

// 如果 realpath 失敗，使用 __DIR__ 的上一層作為備用
if (BASE_PATH === false) {
    define('BASE_PATH', dirname(__DIR__));
}

