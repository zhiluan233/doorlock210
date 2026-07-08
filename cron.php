<?php
/*

计划任务队列执行入口
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

define("ROOT", __DIR__);

require(ROOT . "/Core/vendor/autoload.php");
include(ROOT . "/config.php");
include(ROOT . "/Core/Utils.php");
include(ROOT . "/Core/DataBase.php");
include(ROOT . "/Core/Settings.php");
include(ROOT . "/Core/Migrator.php");
include(ROOT . "/Middleware/Class.Feishu.php");
include(ROOT . "/Middleware/Class.FeishuSync.php");
include(ROOT . "/Middleware/Class.Attendance.php");

if (PHP_SAPI !== 'cli') {
    if (!isset($_GET['key']) || $_GET['key'] !== ($_config['apiCommonKey'] ?? '')) {
        Header("HTTP/1.1 403 Forbidden");
        exit("Forbidden");
    }
    Header("Content-Type: application/json; charset=utf-8");
}

$conn = null;
$db = new Database();

$migration = Migrator::ensure();
$queue = AttendanceService::processAllQueues();
$contactSyncSchedule = FeishuContactSync::scheduleDailyIfDue();
$contactSync = FeishuContactSync::processNextJob(1);

$result = [
    'time' => date('Y-m-d H:i:s'),
    'migration' => $migration,
    'queue' => $queue,
    'contact_sync_schedule' => $contactSyncSchedule,
    'contact_sync' => $contactSync
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
