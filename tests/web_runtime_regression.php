<?php
// Local HTTP failure-path checks only. Uses an intentionally nonexistent database.
$socket=getenv('DOORLOCK_TEST_SOCKET');
if (!$socket) { fwrite(STDERR,"Set DOORLOCK_TEST_SOCKET to an isolated MySQL test socket.\n"); exit(2); }
$root=dirname(__DIR__);
$temporary=sys_get_temp_dir().'/doorlock-web-test-'.bin2hex(random_bytes(6));
mkdir($temporary,0700); mkdir($temporary.'/Public',0700); mkdir($temporary.'/tmp',0700);
foreach (['Core','Middleware','Pages'] as $directory) { symlink($root.'/'.$directory,$temporary.'/'.$directory); }
foreach (['index.php','health.php'] as $file) { copy($root.'/Public/'.$file,$temporary.'/Public/'.$file); }
$config=['timezone'=>'Asia/Shanghai','db_host'=>'localhost','db_port'=>0,'db_user'=>'root','db_pass'=>'',
    'db_name'=>'doorlock_missing_'.bin2hex(random_bytes(8)),'db_socket'=>$socket,'db_code'=>'utf8mb4','apiCommonKey'=>'local-test-key'];
file_put_contents($temporary.'/config.php','<?php $_config='.var_export($config,true).';');
$listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if (!$listener) { throw new RuntimeException($error); }
$address=stream_socket_get_name($listener,false); fclose($listener);
$process=proc_open([PHP_BINARY,'-S',$address,'-t',$temporary.'/Public'],[0=>['pipe','r'],1=>['file',$temporary.'/server.log','a'],2=>['file',$temporary.'/server.log','a']],$pipes);
$checks=0;
function request($path,$method='GET',$token='') {
    global $address;
    $context=stream_context_create(['http'=>['method'=>$method,'timeout'=>3,'ignore_errors'=>true,
        'header'=>$token!=='' ? "Authorization: Bearer {$token}\r\n" : '']]);
    $body=@file_get_contents('http://'.$address.$path,false,$context);
    return [$body,$http_response_header ?? []];
}
function checkHttp($condition,$message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
try {
    $body=false;
    for ($attempt=0;$attempt<30;$attempt++) {
        [$body,$headers]=request('/');
        if ($body!==false) { break; }
        usleep(50000);
    }
    checkHttp(strpos($headers[0] ?? '','503')!==false && strpos($body,'重新加载')!==false,'DB connection failure must produce a useful HTML 503');
    $headerText=strtolower(implode("\n",$headers));
    checkHttp(strpos($headerText,'retry-after: 3')!==false && strpos($headerText,'cache-control: no-store')!==false,'failed pages must be retryable and uncached');
    [$body,$headers]=request('/?action=test_failure','POST'); $json=json_decode($body,true);
    checkHttp(strpos($headers[0] ?? '','503')!==false && $json['ok']===false && $json['retryable']===true,'failed actions must produce a JSON 503 without success acknowledgements');
    checkHttp(strpos($body,$config['db_name'])===false && strpos($body,$socket)===false,'failure responses must not leak database identifiers');
    [$body,$headers]=request('/health.php');
    checkHttp(strpos($headers[0] ?? '','403')!==false,'health details must require authentication');
    $healthPath=$temporary.'/tmp/doorlock_runtime_health.json';
    file_put_contents($healthPath,json_encode(['ok'=>true,'checked_at'=>time()]));
    [$body,$headers]=request('/health.php','GET','local-test-key');
    checkHttp(strpos($headers[0] ?? '','200')!==false && json_decode($body,true)['fresh']===true,'cached health must work independently of an unavailable database');
    file_put_contents($healthPath,json_encode(['ok'=>true,'checked_at'=>time()-301]));
    [$body,$headers]=request('/health.php','GET','local-test-key');
    checkHttp(strpos($headers[0] ?? '','503')!==false && json_decode($body,true)['fresh']===false,'stalled cron snapshots must make health fail');
    echo "Passed {$checks} HTTP failure-path regression checks.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (['Core','Middleware','Pages'] as $directory) { unlink($temporary.'/'.$directory); }
    foreach (['Public','tmp'] as $directory) {
        foreach (glob($temporary.'/'.$directory.'/*') ?: [] as $file) { unlink($file); }
        rmdir($temporary.'/'.$directory);
    }
    foreach (glob($temporary.'/*') ?: [] as $file) { unlink($file); }
    rmdir($temporary);
}
