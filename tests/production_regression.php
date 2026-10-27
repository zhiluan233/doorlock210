<?php
// Read-only business checks against an isolated production backup, plus optional test-only indexes.
namespace anim210System;
date_default_timezone_set('Asia/Shanghai');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$socket=getenv('DOORLOCK_TEST_SOCKET');
$database=getenv('DOORLOCK_PRODUCTION_COPY_DB');
if (!$socket || !preg_match('/^doorlock_prod_test_[a-f0-9_]+$/',(string)$database)) {
    fwrite(STDERR,"Set DOORLOCK_TEST_SOCKET and DOORLOCK_PRODUCTION_COPY_DB to an isolated backup.\n"); exit(2);
}
define('ROOT',dirname(__DIR__));
require ROOT . '/Core/DataBase.php';
require ROOT . '/Core/PerformanceIndexes.php';
require __DIR__ . '/baseline_reference.php';
$conn=new \mysqli('localhost','root','',$database,0,$socket); $conn->set_charset('utf8mb4');
$conn->query("SET time_zone='+08:00'");
$baseline=sys_get_temp_dir().'/doorlock-production-baseline-'.bin2hex(random_bytes(6));
function commandOutput($command) {
    $p=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p)!==0) { throw new \RuntimeException($err); }
    return $out;
}
function readSnapshot($root,$case,$params) {
    return json_decode(commandOutput([PHP_BINARY,__DIR__.'/mysql_regression.php','--child',$root,$GLOBALS['database'],$case,json_encode($params)]),true,512,JSON_THROW_ON_ERROR);
}
function differencePath($a,$b,$path='') {
    if ($a===$b) { return null; }
    if (!is_array($a) || !is_array($b)) { return $path; }
    if (array_keys($a)!==array_keys($b)) { return $path.'/keys'; }
    foreach ($a as $key=>$value) { $diff=differencePath($value,$b[$key],$path.'/'.$key); if ($diff!==null) { return $diff; } }
    return $path;
}
$files=['Core/DataBase.php','Core/Settings.php','Core/Migrator.php','Core/Utils.php','Core/UserCheck.php',
    'Middleware/Class.Attendance.php','Middleware/Class.AttendanceModule.php','Modules/dashboard.php',
    'Modules/attendance.php','Modules/accesslog.php','Modules/operationlog.php','Modules/role.php','Pages/userpanel.php'];
foreach ($files as $file) {
    @mkdir(dirname($baseline.'/'.$file),0700,true);
    file_put_contents($baseline.'/'.$file,stableBaselineReference($file,commandOutput(['git','-C',ROOT,'show',(getenv('DOORLOCK_BASELINE_REF') ?: '33fc6b78').':'.$file])));
}
$indexResult=PerformanceIndexes::run(true);
if (!$indexResult['ok']) { throw new \RuntimeException('Index check failed: '.json_encode($indexResult)); }
echo "Production backup: online indexes installed and checked\n";

$date='2026-10-26'; $today='2026-10-27';
$cases=[
    ['reports',['date_from'=>$date,'date_to'=>$today,'keyword'=>'','trace_limit'=>10],'all report filters, traces, exports'],
    ['reports',['date_from'=>'2026-07-30','date_to'=>$today,'keyword'=>'','trace_limit'=>5],'full historical report range'],
    ['counterparts',['limit'=>25],'pairing and reminder checks'],
    ['Modules/dashboard.php',['start_date'=>$date,'end_date'=>$date],'dashboard yesterday: 9 grains, rankings, unswiped'],
    ['Modules/dashboard.php',['start_date'=>$date,'end_date'=>$today,'action_type'=>'failed','q'=>'B7'],'dashboard cross-day search'],
    ['Modules/attendance.php',['date_from'=>$date,'date_to'=>$today],'attendance page'],
    ['Modules/accesslog.php',['q'=>'B7'],'access log search'],
    ['Modules/role.php',[],'role lists and member counts']
];
$checks=0;
foreach ($cases as [$case,$params,$label]) {
    $started=microtime(true); $before=readSnapshot($baseline,$case,$params); $oldSeconds=microtime(true)-$started;
    $started=microtime(true); $after=readSnapshot(ROOT,$case,$params); $newSeconds=microtime(true)-$started;
    $difference=differencePath($before,$after);
    if ($difference!==null) { throw new \RuntimeException('Production result changed at '.$difference.' in '.$label); }
    $checks++;
    echo 'Equivalent production: '.$label.' before_s='.round($oldSeconds,3).' after_s='.round($newSeconds,3)."\n"; flush();
}
$row=$conn->query("SELECT id,employee_open_id,punch_time FROM attendance_source_records WHERE employee_open_id<>'' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$person=$conn->real_escape_string($row['employee_open_id']); $id=intval($row['id']); $from=intval($row['punch_time'])-300; $to=$from+600;
$old="SELECT id FROM attendance_source_records WHERE id<>{$id} AND punch_time BETWEEN {$from} AND {$to} AND source_kind='face' AND (CONCAT('open:',employee_open_id)='open:{$person}' OR CONCAT('no:',employee_no)='open:{$person}' OR CONCAT('user:',employee_user_id)='open:{$person}') LIMIT 1";
$new="SELECT id FROM attendance_source_records WHERE id<>{$id} AND punch_time BETWEEN {$from} AND {$to} AND source_kind='face' AND employee_open_id='{$person}' LIMIT 1";
foreach (['before'=>$old,'after'=>$new] as $label=>$sql) {
    $plan=$conn->query('EXPLAIN '.$sql)->fetch_assoc();
    echo 'Pairing plan '.$label.': '.json_encode(array_intersect_key($plan,array_flip(['type','key','rows','Extra'])))."\n";
}
$from=strtotime($date.' 00:00:00'); $to=strtotime($date.' 23:59:59');
foreach (['before'=>"`time` BETWEEN {$from} AND {$to}",'after'=>Database::logTimeRange($from,$to)] as $label=>$where) {
    $plan=$conn->query("EXPLAIN SELECT COUNT(*) FROM logs WHERE {$where}")->fetch_assoc();
    echo 'Log time plan '.$label.': '.json_encode(array_intersect_key($plan,array_flip(['type','key','rows','Extra'])))."\n";
}
echo "Passed {$checks} production-backup comparison groups.\n";
