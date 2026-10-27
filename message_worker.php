<?php
namespace anim210System;
if (PHP_SAPI!=='cli') { http_response_code(403); exit('CLI only'); }
define('ROOT',__DIR__);
require ROOT . '/Core/MessageWorker.php';
// Acquire before opening MySQL; redundant wakeups must not consume DB connections.
$workerLock=MessageWorker::acquire(true);
if (!$workerLock) { exit; }
require ROOT . '/Core/vendor/autoload.php';
require ROOT . '/config.php';
require ROOT . '/Core/Utils.php';
require ROOT . '/Core/DataBase.php';
require ROOT . '/Core/Settings.php';
require ROOT . '/Core/Migrator.php';
require ROOT . '/Middleware/Class.Feishu.php';
require ROOT . '/Middleware/Class.Attendance.php';
require ROOT . '/Middleware/Class.FocusCard.php';
try {
    new Database();
    if (empty(Migrator::ensure()['ok'])) { throw new \RuntimeException('Database migration unavailable'); }
    echo json_encode(MessageWorker::drain(),JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (\Throwable $error) {
    error_log('doorlock message worker failed type=' . get_class($error) . ' code=' . $error->getCode());
    exit(1);
} finally { flock($workerLock,LOCK_UN); fclose($workerLock); }
