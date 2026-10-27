<?php
namespace anim210System;
if (!defined('ROOT') || !isset($conn,$checks)) { exit(2); }

$archiveDirectory = sys_get_temp_dir() . '/doorlock-archive-test-' . bin2hex(random_bytes(6));
$_config['archive'] = ['enabled'=>true,'directory'=>$archiveDirectory,'min_free_bytes'=>0,'batch_size'=>1000];
$oldTime = strtotime('-2 years',TEST_NOW);
$oldDate = date('Y-m-d',$oldTime);
$originalRaw = json_encode(['type'=>7,'location_name'=>'原始位置','evidence'=>str_repeat('exact-byte-evidence-',150)],JSON_UNESCAPED_UNICODE);
insertFixture('attendance_source_records',['id'=>9999,'source'=>'feishu','source_kind'=>'face','external_id'=>'archive_fixture',
    'employee_open_id'=>'ou_archive','punch_time'=>$oldTime,'punch_date'=>$oldDate,'raw_payload'=>$originalRaw]);
insertFixture('attendance_daily_reports',['report_hash'=>hash('sha256','archive_fixture'),'person_key'=>'open:ou_archive','work_date'=>$oldDate]);
$archiveReport = intval($conn->insert_id);
$beforeTrace = AttendanceModuleService::sourceRecordsForReport($archiveReport);
try {
    $_config['archive']['min_free_bytes']=PHP_INT_MAX;
    checkResult(ArchiveStore::runScheduled(true)['status']==='archive_disk_low','low archive capacity must stop before moving data');
    checkResult(sqlRows('SELECT raw_payload FROM attendance_source_records WHERE id=9999')[0]['raw_payload']===$originalRaw,'low archive capacity must retain all original online bytes');
    $_config['archive']['min_free_bytes']=0;
    $archive = ArchiveStore::runScheduled(true);
    checkResult($archive['ok'] && $archive['offloaded_fields']===1,'old source payload must be archived');
    $native = sqlRows('SELECT raw_payload FROM attendance_source_records WHERE id=9999')[0]['raw_payload'];
    checkResult(strpos($native,ArchiveStore::PREFIX)===0,'hot row must contain an archive reference');
    checkResult(AttendanceModuleService::sourceRecordsForReport($archiveReport)===$beforeTrace,'archive must preserve the complete history trace byte for byte');
    checkResult(Database::querySingleLine('attendance_source_records',['id'=>9999])['raw_payload']===$originalRaw,'single-row reads must hydrate archives');

    // Re-importing an old flow must notify maintenance even after the monotonic cursor passed it.
    $changedRaw = json_encode(['type'=>8,'evidence'=>str_repeat('new-late-evidence',200)]);
    privateCall('upsertSourceRecord',['source'=>'feishu','source_kind'=>'face','external_id'=>'archive_fixture',
        'employee_open_id'=>'ou_archive','punch_time'=>$oldTime,'punch_date'=>$oldDate,'raw_payload'=>$changedRaw]);
    checkResult(count(sqlRows("SELECT * FROM runtime_archive_dirty WHERE table_name='attendance_source_records'"))===1,'late historical writes must enter the archive retry queue');
    $conn->query("UPDATE runtime_archive_state SET last_id=0 WHERE table_name='__rotation__'");
    $archive=ArchiveStore::runScheduled(true);
    checkResult($archive['ok'] && Database::querySingleLine('attendance_source_records',['id'=>9999])['raw_payload']===$changedRaw,'late historical payloads must archive without losing updates');

    // A DB failure after file fsync must leave the original hot payload intact.
    insertFixture('attendance_source_records',['id'=>10000,'source'=>'feishu','external_id'=>'archive_rollback_fixture',
        'punch_time'=>$oldTime+1,'punch_date'=>$oldDate,'raw_payload'=>$originalRaw]);
    $conn->query("CREATE TRIGGER test_archive_failure BEFORE UPDATE ON attendance_source_records FOR EACH ROW BEGIN IF NEW.id=10000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected archive failure'; END IF; END");
    $conn->query("UPDATE runtime_archive_state SET last_id=0 WHERE table_name='__rotation__'");
    $failed=ArchiveStore::runScheduled(true);
    checkResult(!$failed['ok'],'injected archive transaction failure must be reported');
    checkResult(sqlRows('SELECT raw_payload FROM attendance_source_records WHERE id=10000')[0]['raw_payload']===$originalRaw,'failed archive commit must retain the original payload');
    checkResult(count(sqlRows('SELECT token FROM runtime_archives WHERE record_id=10000'))===0,'failed archive commit must roll back its manifest');
    $conn->query('DROP TRIGGER test_archive_failure');
    checkResult(ArchiveStore::runScheduled(true)['ok'],'archive must recover after an interrupted transaction');
    checkResult(Database::querySingleLine('attendance_source_records',['id'=>10000])['raw_payload']===$originalRaw,'recovery must read the correct immutable chunk past any orphan tail');

    $entry=sqlRows('SELECT * FROM runtime_archives WHERE record_id=10000')[0];
    $pack=$archiveDirectory . '/' . $entry['file_name'];
    rename($pack,$pack.'.offline');
    ArchiveStore::clearCache(); $rejected=false;
    try { Database::querySingleLine('attendance_source_records',['id'=>10000]); }
    catch (\Throwable $e) { $rejected=true; }
    rename($pack.'.offline',$pack);
    checkResult($rejected,'missing archives must fail explicitly instead of returning empty evidence');
    ArchiveStore::clearCache();
    $file=fopen($pack,'r+b'); fseek($file,intval($entry['byte_offset'])); $byte=fread($file,1);
    fseek($file,intval($entry['byte_offset'])); fwrite($file,chr(ord($byte)^1)); fclose($file);
    $rejected=false;
    try { ArchiveStore::read($entry['token']); } catch (\Throwable $e) { $rejected=true; }
    $file=fopen($pack,'r+b'); fseek($file,intval($entry['byte_offset'])); fwrite($file,$byte); fclose($file);
    checkResult($rejected,'archive corruption must be detected');
    ArchiveStore::clearCache();
    checkResult(Database::querySingleLine('attendance_source_records',['id'=>10000])['raw_payload']===$originalRaw,'restored archive volume must immediately recover exact data');

    $attempted=new \ReflectionProperty(OptionalRedisCache::class,'attempted'); $attempted->setAccessible(true);
    $client=new \ReflectionProperty(OptionalRedisCache::class,'client'); $client->setAccessible(true);
    $fakeCache=new class {
        public $fail=false;
        public function get($key) { if ($this->fail) { throw new \RuntimeException('Redis offline'); } return gzencode('corrupt cache value'); }
        public function setex($key,$ttl,$value) { if ($this->fail) { throw new \RuntimeException('Redis offline'); } }
    };
    $attempted->setValue(null,true); $client->setValue(null,$fakeCache);
    ArchiveStore::clearCache();
    checkResult(ArchiveStore::read($entry['token'])['raw_payload']===$originalRaw,'corrupt Redis cache must fall back to verified archive bytes');
    $fakeCache->fail=true; ArchiveStore::clearCache();
    checkResult(ArchiveStore::read($entry['token'])['raw_payload']===$originalRaw,'Redis connection failures must not lose historical data');
    $client->setValue(null,null);

    $cursorBefore=sqlRows("SELECT last_time,last_id FROM runtime_archive_state WHERE table_name='attendance_source_records'")[0];
    $conn->query("UPDATE runtime_archive_state SET last_id=0 WHERE table_name='__rotation__'");
    ArchiveStore::runScheduled(true);
    checkResult(sqlRows("SELECT last_time,last_id FROM runtime_archive_state WHERE table_name='attendance_source_records'")[0]===$cursorBefore,'caught-up archive scans must not reset and rescan all history');

    Database::configureRequestBudget(1,50);
    $started=microtime(true);
    try { Database::executeQuery($conn,'SELECT SLEEP(0.5)'); } catch (\mysqli_sql_exception $e) {}
    checkResult(microtime(true)-$started<0.3,'a timed-out read must stop within the database budget');
    Database::configureRequestBudget(0.001); usleep(5000); $rejected=false;
    try { Database::executeQuery($conn,'SELECT 1'); } catch (\RuntimeException $e) { $rejected=true; }
    checkResult($rejected,'expired requests must not launch more queries');
    Database::configureRequestBudget(0);

    Settings::set('oa_attendance_enabled','true');
    $employee=sqlRows('SELECT * FROM employee WHERE id=1')[0];
    $device=sqlRows('SELECT * FROM devices WHERE id=1')[0];
    $countBefore=sqlRows('SELECT COUNT(*) n FROM logs')[0]['n'];
    $conn->query("CREATE TRIGGER test_swipe_failure BEFORE INSERT ON attendance_queue FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected swipe failure'");
    $rejected=false;
    try { AttendanceService::persistEmployeeSwipe($employee,$device,'0000000001',TEST_NOW+100); }
    catch (\Throwable $e) { $rejected=true; }
    $conn->query('DROP TRIGGER test_swipe_failure');
    checkResult($rejected && sqlRows('SELECT COUNT(*) n FROM logs')[0]['n']===$countBefore,'a failed queue insert must roll back the access log before acknowledging a swipe');
    AttendanceService::persistEmployeeSwipe($employee,$device,'0000000001',TEST_NOW+100);
    checkResult(intval(sqlRows('SELECT COUNT(*) n FROM logs')[0]['n'])===intval($countBefore)+1,'a successful swipe must commit its log and attendance writes');
    mysqli_report(MYSQLI_REPORT_OFF); $rejected=false;
    try { @Database::executeQuery($conn,'SELECT * FROM nonexistent_regression_table'); }
    catch (\mysqli_sql_exception $e) { $rejected=true; }
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    checkResult($rejected,'database failures must throw even when mysqli exception reporting is disabled');
    $previousConfig=$_config; $oldConnection=$conn;
    $_config+=['db_host'=>'localhost','db_user'=>'root','db_pass'=>'','db_port'=>0,'db_code'=>'utf8mb4'];
    $_config['db_name']=$database; $_config['db_socket']=$socket;
    Database::configureRequestBudget(0.001); usleep(5000);
    new Database();
    checkResult(Database::querySingleLine('logs','SELECT 1 AS ok',true)['ok']==='1','CLI connections must start with a fresh unlimited request budget');
    $conn->close(); $conn=$oldConnection; $_config=$previousConfig;
    echo "Verified: archive transparency, late updates, crash recovery, corruption detection, query deadlines and swipe rollback\n";
} finally {
    Database::configureRequestBudget(0);
    $_config['archive']['enabled']=false;
    ArchiveStore::clearCache();
    foreach (glob($archiveDirectory.'/*') ?: [] as $file) { unlink($file); }
    if (is_dir($archiveDirectory)) { rmdir($archiveDirectory); }
}
