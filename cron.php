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

if (PHP_SAPI !== 'cli') {
    if (!isset($_GET['key']) || $_GET['key'] !== ($_config['apiCommonKey'] ?? '')) {
        Header("HTTP/1.1 403 Forbidden");
        exit("Forbidden");
    }
    Header("Content-Type: application/json; charset=utf-8");
}

$cronLock = acquireCronLock();
if (!$cronLock['ok']) {
    $lockedResult = [
        'time' => date('Y-m-d H:i:s'),
        'status' => 'skipped',
        'message' => '上一轮计划任务仍在执行，本轮跳过，避免重复处理队列',
        'lock_file' => $cronLock['path']
    ];
    echo json_encode($lockedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

include(ROOT . "/Core/Utils.php");
include(ROOT . "/Core/DataBase.php");
include(ROOT . "/Core/Settings.php");
include(ROOT . "/Core/Migrator.php");
include(ROOT . "/Core/OperationLog.php");
include(ROOT . "/Core/RuntimeMaintenance.php");
include(ROOT . "/Middleware/Class.Feishu.php");
include(ROOT . "/Middleware/Class.FeishuSync.php");
include(ROOT . "/Middleware/Class.Attendance.php");

$conn = null;
$db = new Database();

$migration = Migrator::ensure();
$maintenance = RuntimeMaintenance::runScheduledCleanup();
$queue = AttendanceService::processAllQueues();
$contactSyncSchedule = FeishuContactSync::scheduleDailyIfDue();
$contactSync = FeishuContactSync::processNextJob(1);

$result = [
    'time' => date('Y-m-d H:i:s'),
    'migration' => $migration,
    'maintenance' => $maintenance,
    'queue' => $queue,
    'contact_sync_schedule' => $contactSyncSchedule,
    'contact_sync' => $contactSync
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

function acquireCronLock()
{
    $lockDir = ROOT . '/tmp';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }
    if (!is_dir($lockDir) || !is_writable($lockDir)) {
        $lockDir = sys_get_temp_dir();
    }

    $lockFile = rtrim($lockDir, '/\\') . '/doorlock_cron.lock';
    $fp = @fopen($lockFile, 'c');
    if (!$fp) {
        return ['ok' => false, 'path' => $lockFile];
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return ['ok' => false, 'path' => $lockFile];
    }

    ftruncate($fp, 0);
    fwrite($fp, json_encode([
        'pid' => getmypid(),
        'started_at' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE));
    fflush($fp);

    register_shutdown_function(function () use ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    });

    return ['ok' => true, 'path' => $lockFile];
}
