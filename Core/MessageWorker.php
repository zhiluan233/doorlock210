<?php
namespace anim210System;

/** Wake a bounded CLI consumer; HTTP shutdown handlers never send network messages. */
class MessageWorker {
    private static $scheduled = false;

    private static function lockPath()
    {
        $directory = ROOT . '/tmp';
        if (!is_dir($directory) || !is_writable($directory)) { $directory=sys_get_temp_dir(); }
        return $directory . '/doorlock_message_worker.lock';
    }

    public static function acquire($waitForLauncher = false)
    {
        $file = @fopen(self::lockPath(),'c+');
        if (!$file) { return null; }
        $deadline=microtime(true)+($waitForLauncher ? 0.2 : 0);
        do {
            if (flock($file,LOCK_EX|LOCK_NB)) { return $file; }
            if (!$waitForLauncher) { break; }
            usleep(20000);
        } while (microtime(true)<$deadline);
        fclose($file); return null;
    }

    public static function schedule()
    {
        if (self::$scheduled || PHP_SAPI==='cli') { return; }
        self::$scheduled=true;
        register_shutdown_function(function() {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            self::wake();
        });
    }

    public static function wake()
    {
        // Check the consumer lock before forking, so a swipe peak does not fork per event.
        $lock=self::acquire();
        if (!$lock) { return false; }
        try {
            rewind($lock);
            if (microtime(true)-floatval(stream_get_contents($lock))<1) { return false; }
            if (!function_exists('exec') || in_array('exec',array_map('trim',explode(',',(string)ini_get('disable_functions'))),true)) { return false; }
            $configured=trim((string)($GLOBALS['_config']['phpCliBinary'] ?? ($GLOBALS['_config']['workerPhpBinary'] ?? '')));
            $binary=$configured!=='' ? $configured : PHP_BINARY;
            if (preg_match('/php-(fpm|cgi)/i',basename($binary))) { $binary='php'; }
            $script=ROOT . '/message_worker.php';
            if (!is_file($script)) { return false; }
            ftruncate($lock,0); rewind($lock); fwrite($lock,(string)microtime(true)); fflush($lock);
            exec(escapeshellarg($binary) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
            return true;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public static function drain($seconds = 20)
    {
        $started=microtime(true); $processed=0;
        do {
            $focus=FocusCardAlertService::processQueue(10);
            $normal=AttendanceService::processMessageQueue(10);
            $count=intval($focus['total'])+intval($normal['total']);
            $processed+=$count;
            if ($count===0) { break; }
        } while (microtime(true)-$started<max(1,intval($seconds)));
        return ['ok'=>true,'processed'=>$processed];
    }
}
