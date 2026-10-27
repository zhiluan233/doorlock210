<?php
// Isolated consumer/launcher test doubles: never opens MySQL or sends messages.
namespace anim210System;
define('ROOT',dirname(__DIR__));
require ROOT . '/Core/MessageWorker.php';
$commands=[];
function exec($command) { $GLOBALS['commands'][]=$command; }
class FocusCardAlertService {
    public static $calls=0;
    public static function processQueue($limit) { return ['total'=>self::$calls++===0 ? 2 : 0]; }
}
class AttendanceService {
    public static $calls=0;
    public static function processMessageQueue($limit) { return ['total'=>self::$calls++===0 ? 3 : 0]; }
}
$path=ROOT . '/tmp/doorlock_message_worker.lock';
$previous=is_file($path) ? file_get_contents($path) : null;
try {
    $lock=MessageWorker::acquire();
    if (!$lock || MessageWorker::wake()!==false || count($commands)!==0) { throw new \RuntimeException('Active consumers must suppress redundant forks'); }
    flock($lock,LOCK_UN); fclose($lock);
    file_put_contents($path,'0');
    $GLOBALS['_config']['phpCliBinary']='/path with spaces/php';
    if (!MessageWorker::wake() || count($commands)!==1 || strpos($commands[0],escapeshellarg('/path with spaces/php'))!==0) {
        throw new \RuntimeException('Wakeup must launch an escaped CLI command');
    }
    if (MessageWorker::wake()!==false || count($commands)!==1) { throw new \RuntimeException('Wakeups must be throttled during a burst'); }
    if (FocusCardAlertService::$calls!==0 || AttendanceService::$calls!==0) { throw new \RuntimeException('A launcher must never send network messages'); }
    $result=MessageWorker::drain();
    if ($result['processed']!==5 || FocusCardAlertService::$calls!==2 || AttendanceService::$calls!==2) {
        throw new \RuntimeException('CLI consumer must drain both durable message queues until idle');
    }
    echo "Passed 5 CLI message-worker regression checks.\n";
} finally {
    if ($previous!==null) { file_put_contents($path,$previous); }
    elseif (is_file($path)) { unlink($path); }
}
