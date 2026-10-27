<?php
namespace anim210System;

class RuntimeHealth {
    public static function run($maintenance = [])
    {
        global $conn,$_config;
        $checks = [];
        $paths = ['database'=>(($_config['maintenance']['database_volume_path'] ?? '') ?: ROOT)];
        if (!empty($_config['archive']['enabled']) && !empty($_config['archive']['directory'])) {
            $paths['archive'] = $_config['archive']['directory'];
        }
        foreach ($paths as $name=>$path) {
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            $minimum = max(0,intval($_config['maintenance']['min_free_bytes'] ?? 5368709120));
            $ratio = max(0,min(0.5,floatval($_config['maintenance']['min_free_ratio'] ?? 0.1)));
            $checks[$name] = ['ok'=>$free!==false && $total!==false && $free>=$minimum && ($total<=0 || $free/$total>=$ratio),
                'free_bytes'=>$free===false ? null : $free,'total_bytes'=>$total===false ? null : $total];
        }
        $durability = \mysqli_fetch_assoc(Database::executeQuery($conn,'SELECT @@innodb_flush_log_at_trx_commit AS flush_at_commit,@@sync_binlog AS sync_binlog,@@log_bin AS log_bin'));
        $durability['ok'] = intval($durability['flush_at_commit'])===1 && (!intval($durability['log_bin']) || intval($durability['sync_binlog'])===1);
        $tables = \mysqli_fetch_all(Database::executeQuery($conn,"SELECT `table_name`,`table_rows`,`data_length`,`index_length`,`data_free` FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY data_length+index_length DESC LIMIT 15"),MYSQLI_ASSOC);
        $result = ['checked_at'=>time(),'volumes'=>$checks,'durability'=>$durability,'tables'=>$tables,'maintenance'=>$maintenance];
        $result['ok'] = $durability['ok'] && count(array_filter($checks,function($check){return !$check['ok'];}))===0
            && count(array_filter($maintenance,function($check){return empty($check['ok']);}))===0;
        $path = ROOT . '/tmp/doorlock_runtime_health.json';
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp,json_encode($result,JSON_UNESCAPED_UNICODE),LOCK_EX)!==false) { @rename($tmp,$path); }
        return $result;
    }
    public static function snapshot()
    {
        $path = ROOT . '/tmp/doorlock_runtime_health.json';
        if (!is_file($path)) { return null; }
        return json_decode((string)@file_get_contents($path),true);
    }
}
