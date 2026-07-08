<?php

namespace anim210System;

define("ROOT", __DIR__);

require(ROOT . "/Core/vendor/autoload.php");
include(ROOT . "/config.php");
include(ROOT . "/Core/Utils.php");
include(ROOT . "/Core/DataBase.php");
include(ROOT . "/Core/Settings.php");
include(ROOT . "/Core/Migrator.php");
include(ROOT . "/Middleware/Class.Feishu.php");
include(ROOT . "/Middleware/Class.Attendance.php");

if (PHP_SAPI !== 'cli') {
    if (!isset($_GET['key']) || $_GET['key'] !== ($_config['apiCommonKey'] ?? '')) {
        Header("HTTP/1.1 403 Forbidden");
        exit("Forbidden");
    }
    Header("Content-Type: application/json; charset=utf-8");
}

$conn = null;
$db = new anim210System\Database();

$migration = anim210System\Migrator::ensure();
$queue = anim210System\AttendanceService::processAllQueues();

$result = [
    'time' => date('Y-m-d H:i:s'),
    'migration' => $migration,
    'queue' => $queue
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
