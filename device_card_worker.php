<?php
/*

门禁设备端侧卡库独立下发任务入口
Ver 1.0.0.0 20260726
Code by Jason / Codex

*/

namespace anim210System;

if (PHP_SAPI!=='cli') { http_response_code(403); exit('CLI only'); }

define("ROOT", __DIR__);

require(ROOT . "/Core/vendor/autoload.php");
include(ROOT . "/config.php");
include(ROOT . "/Core/Utils.php");
include(ROOT . "/Core/DataBase.php");
include(ROOT . "/Core/Settings.php");
include(ROOT . "/Core/Migrator.php");
include(ROOT . "/Middleware/Class.Attendance.php");
include(ROOT . "/Core/DeviceCardSync.php");

$conn = null;
$db = new Database();
$migration = Migrator::ensure();
if (empty($migration['ok'])) {
    error_log('doorlock migration: ' . ($migration['message'] ?? 'unknown error'));
    exit(1);
}

$result = DeviceCardSync::runWorker();

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}
