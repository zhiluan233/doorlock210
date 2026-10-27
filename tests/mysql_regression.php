<?php
// DOORLOCK_TEST_SOCKET=/path/to/test.sock php tests/mysql_regression.php
// Creates and drops only a random doorlock_perf_test_* database on that socket.
namespace anim210System;

date_default_timezone_set('Asia/Shanghai');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$socket = getenv('DOORLOCK_TEST_SOCKET');
if (!$socket || !is_file($socket) && !file_exists($socket)) {
    fwrite(STDERR, "Set DOORLOCK_TEST_SOCKET to an isolated MySQL 8 test socket.\n");
    exit(2);
}
$child = ($argv[1] ?? '') === '--child';
$codeRoot = $child ? $argv[2] : dirname(__DIR__);
define('ROOT', $codeRoot);
define('TEST_NOW', strtotime(date('Y-m-d') . ' 23:00:00'));
function time() { return TEST_NOW; }
$_config = ['timezone' => 'Asia/Shanghai', 'feishu' => ['keyConfigFile' => __FILE__]];
foreach (['Core/DataBase.php', 'Core/Settings.php', 'Core/Migrator.php', 'Core/Utils.php', 'Core/UserCheck.php',
    'Middleware/Class.Attendance.php', 'Middleware/Class.AttendanceModule.php'] as $file) {
    require $codeRoot . '/' . $file;
}
$conn = new \mysqli('localhost', 'root', '', '', 0, $socket);
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone='+08:00'");

function sqlRows($sql) { return $GLOBALS['conn']->query($sql)->fetch_all(MYSQLI_ASSOC); }
function insertFixture($table, $data) {
    $columns = implode(',', array_map(function ($key) { return '`' . $key . '`'; }, array_keys($data)));
    $values = implode(',', array_map(function ($value) {
        return $value === null ? 'NULL' : "'" . $GLOBALS['conn']->real_escape_string((string)$value) . "'";
    }, array_values($data)));
    $GLOBALS['conn']->query("INSERT INTO `{$table}` ({$columns}) VALUES ({$values})");
}
function privateCall($method, ...$args) {
    $reflection = new \ReflectionMethod(AttendanceModuleService::class, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null, ...$args);
}
function withoutAuditFields($rows) {
    foreach ($rows as &$row) {
        foreach (['id', 'created_at', 'updated_at', 'calculated_at', 'source_updated_at'] as $key) { unset($row[$key]); }
    }
    return $rows;
}
function renderSnapshot($module, $params) {
    global $conn, $_config;
    $_GET = $params;
    $admin = sqlRows("SELECT username FROM user WHERE type IN ('admin','readonly') ORDER BY id LIMIT 1");
    $member = sqlRows("SELECT open_id FROM employee WHERE open_id<>'' ORDER BY id LIMIT 1");
    $_SESSION = ['user' => $admin[0]['username'] ?? 'admin_test', 'member_open_id' => $member[0]['open_id'] ?? 'ou_1', 'token' => 'test_token'];
    ob_start();
    if ($module==='Modules/role.php') { $conn->begin_transaction(); }
    include ROOT . '/' . $module;
    if ($module==='Modules/role.php') { $conn->rollback(); }
    $html = ob_get_clean();
    $variables = get_defined_vars();
    $keys = ['summary', 'total', 'rows', 'groups', 'totalPages', 'hasMore', 'pageNo', 'filterDate',
        'typeRows', 'doorRows', 'personRows', 'dayRows', 'weekdayRows', 'edgeTimes', 'latestRows',
        'chartSeriesByGrain', 'throughputPeaks', 'chartDetailRows', 'dailyUnswipedRows',
        'rolesRaw','membersRaw','roles'];
    return array_intersect_key($variables, array_flip($keys));
}

if ($child) {
    $database = $argv[3];
    if (!preg_match('/^doorlock_(perf|prod)_test_[a-f0-9_]+$/', $database)) { throw new \RuntimeException('Unsafe database name'); }
    $conn->select_db($database);
    $case = $argv[4];
    $params = json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR);
    if ($case === 'calculate') {
        foreach (sqlRows('SELECT * FROM employee ORDER BY id') as $employee) {
            $key = privateCall('personKeyFromEmployee', $employee);
            if ($key !== '') { privateCall('recalculatePersonDay', $key, $params['date'], $employee, false); }
        }
        $result = [
            'reports' => withoutAuditFields(sqlRows('SELECT * FROM attendance_daily_reports ORDER BY person_key,work_date')),
            'effective' => withoutAuditFields(sqlRows('SELECT * FROM attendance_effective_records ORDER BY person_key,effective_time,pair_hash'))
        ];
    } elseif ($case === 'reports') {
        $result = [];
        foreach (['all','normal','late','early_leave','absent','full_absent','work_start_valid','work_end_valid',
            'only_face','only_badge','invalid_late_related','invalid_early_leave_related','missing_checkout'] as $status) {
            $filter = $params + ['status' => $status];
            $result[$status] = [AttendanceModuleService::reportSummary($filter), AttendanceModuleService::reportCount($filter),
                AttendanceModuleService::reportRows($filter, 50, 0), AttendanceModuleService::exportReportsXml($filter)];
        }
        $result['traces'] = [];
        $traceLimit = isset($params['trace_limit']) ? ' LIMIT ' . max(1,min(100,intval($params['trace_limit']))) : '';
        foreach (sqlRows('SELECT id FROM attendance_daily_reports ORDER BY id' . $traceLimit) as $row) {
            $result['traces'][] = AttendanceModuleService::sourceRecordsForReport($row['id']);
        }
    } elseif ($case === 'counterparts') {
        $result = [];
        $limit = isset($params['limit']) ? ' LIMIT ' . max(1,min(300,intval($params['limit']))) : '';
        foreach (sqlRows('SELECT * FROM attendance_source_records ORDER BY id DESC' . $limit) as $row) {
            $result[] = [privateCall('sourceRecordHasCounterpart', $row, 300),
                privateCall('sourceRecordAlreadyEffective', $row),
                privateCall('incompleteReminderWindowAlreadyHandled', $row, ['phase' => 'start'], 300)];
        }
    } elseif ($case === 'dedup') {
        $employee = sqlRows('SELECT * FROM employee WHERE id=1')[0];
        $result = [];
        foreach ($params['events'] as $event) {
            $result[] = privateCall('localBadgeSourceExists', $employee, ...$event);
        }
    } else {
        $result = renderSnapshot($case, $params);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

require ROOT . '/Core/PerformanceIndexes.php';
require __DIR__ . '/baseline_reference.php';
$database = 'doorlock_perf_test_' . bin2hex(random_bytes(6));
$baseline = sys_get_temp_dir() . '/doorlock-baseline-' . bin2hex(random_bytes(6));
$checks = 0;
function checkResult($condition, $message) {
    if (!$condition) { throw new \RuntimeException($message); }
    $GLOBALS['checks']++;
}
function processOutput($command) {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new \RuntimeException($err . $out); }
    return $out;
}
function snapshot($root, $case, $params) {
    return json_decode(processOutput([PHP_BINARY, __FILE__, '--child', $root, $GLOBALS['database'], $case,
        json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]), true, 512, JSON_THROW_ON_ERROR);
}
function equivalent($case, $params, $label) {
    $before = snapshot($GLOBALS['baseline'], $case, $params);
    $after = snapshot(ROOT, $case, $params);
    if ($before !== $after) {
        file_put_contents(sys_get_temp_dir() . '/doorlock-before.json', json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents(sys_get_temp_dir() . '/doorlock-after.json', json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    checkResult($before === $after, 'Changed result: ' . $label . ' (see /tmp/doorlock-{before,after}.json)');
    echo "Equivalent: {$label}\n";
}

try {
    // Read the committed version into /tmp, without modifying the working tree.
    $files = ['Core/DataBase.php','Core/Settings.php','Core/Migrator.php','Core/Utils.php','Core/UserCheck.php',
        'Middleware/Class.Attendance.php','Middleware/Class.AttendanceModule.php','Modules/dashboard.php',
        'Modules/attendance.php','Modules/accesslog.php','Modules/operationlog.php','Modules/role.php','Pages/userpanel.php'];
    foreach ($files as $file) {
        @mkdir(dirname($baseline . '/' . $file), 0700, true);
        $ref = getenv('DOORLOCK_BASELINE_REF') ?: '33fc6b78';
        file_put_contents($baseline . '/' . $file, stableBaselineReference($file,processOutput(['git','-C',ROOT,'show',$ref . ':' . $file])));
    }
    $conn->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $conn->select_db($database);
    $legacy = [
        'employee' => "id bigint unsigned PRIMARY KEY AUTO_INCREMENT, name varchar(255) DEFAULT '', realname varchar(255) DEFAULT '', mobile varchar(64) DEFAULT '', employee_id varchar(128) DEFAULT '', open_id varchar(128) DEFAULT '', status varchar(16) DEFAULT 'true'",
        'guest' => "id bigint unsigned PRIMARY KEY AUTO_INCREMENT, name varchar(255) DEFAULT '', open_id varchar(128) DEFAULT '', card_id varchar(64) DEFAULT '', status varchar(16) DEFAULT 'true'",
        'devices' => "id bigint unsigned PRIMARY KEY AUTO_INCREMENT, name varchar(255) DEFAULT '', did varchar(128) DEFAULT '', ip varchar(64) DEFAULT ''",
        'user' => "id bigint unsigned PRIMARY KEY AUTO_INCREMENT, username varchar(128) DEFAULT '', type varchar(32) DEFAULT 'admin'",
        'logs' => "id bigint unsigned PRIMARY KEY AUTO_INCREMENT, passusername varchar(255), passusertype varchar(32), passdoor varchar(255), cardid text, action varchar(255), time text"
    ];
    foreach ($legacy as $table => $columns) { $conn->query("CREATE TABLE `{$table}` ({$columns}) ENGINE=InnoDB"); }
    $migration = Migrator::ensure();
    checkResult($migration['ok'], 'Migration failed: ' . $migration['message']);
    $today = date('Y-m-d');
    $date = date('Y-m-d', strtotime($today . ' -1 day'));
    $emptyDate = date('Y-m-d', strtotime($today . ' -30 days'));
    Settings::setMany(['attendance_module_enabled' => 'true', 'attendance_exempt_location_prefixes' => '外部',
        'attendance_direct_success_flow_types' => '7', 'attendance_oa_push_enabled' => 'false']);
    insertFixture('user', ['username' => 'admin_test', 'type' => 'admin']);
    insertFixture('devices', ['id' => 1, 'name' => 'B7', 'did' => 'test_device']);
    for ($i = 1; $i <= 16; $i++) {
        insertFixture('employee', ['id' => $i, 'name' => sprintf('员工%02d', $i), 'realname' => '测试姓名',
            'employee_id' => $i === 10 ? '' : 'E' . $i, 'open_id' => in_array($i, [9,10]) ? '' : 'ou_' . $i,
            'user_id' => 'u_' . $i, 'card_id' => str_pad((string)$i,10,'0',STR_PAD_LEFT), 'status' => $i === 16 ? 'false' : 'true']);
    }
    foreach (['123', '0000000123', 'ABC', '12345678901', '0000000000', ' 123 ', ''] as $i => $card) {
        insertFixture('learner', ['student_no' => 'S' . $i, 'name' => '学员' . $i, 'card_id' => $card]);
    }
    $sourceId = 1;
    $patterns = [
        1 => [['badge','09:25:00'],['face','09:26:00'],['badge','18:30:00'],['face','18:31:00']],
        2 => [['badge','10:00:00'],['face','10:02:00']], 3 => [['face','09:20:00']],
        4 => [['badge','09:20:00']], 5 => [['exempt_face','09:20:00']], 6 => [['face','09:40:00']],
        7 => [['badge','09:15:00'],['face','09:16:00']],
        8 => [['badge','09:20:00'],['feishu_badge','09:20:01'],['face','09:21:00']],
        9 => [['face','09:25:00'],['badge','09:26:00']], 10 => [['badge','09:25:00'],['face','09:26:00']],
        11 => [['badge','23:59:59']], 12 => [['face','09:25:00']], 13 => [['badge','09:29:59'],['face','09:34:59']]
    ];
    foreach ($patterns as $i => $events) {
        foreach ($events as [$kind, $clock]) {
            insertFixture('attendance_source_records', ['id' => $sourceId, 'source' => $kind === 'badge' ? 'badge' : 'feishu',
                'source_kind' => $kind, 'external_id' => 'event_' . $sourceId, 'employee_open_id' => in_array($i,[9,10]) ? '' : 'ou_' . $i,
                'employee_user_id' => 'u_' . $i, 'employee_no' => $i === 10 ? '' : 'E' . $i, 'employee_name' => sprintf('员工%02d',$i),
                'card_id' => str_pad((string)$i,10,'0',STR_PAD_LEFT), 'punch_time' => strtotime($date . ' ' . $clock),
                'punch_date' => $date, 'flow_type' => $i === 6 ? 7 : 1, 'location_name' => $i === 5 ? '外部办公室' : '工牌-B7',
                'warning_status' => $sourceId % 2 ? 'sent' : 'failed', 'raw_payload' => json_encode(['type'=>1]),
                'created_at' => TEST_NOW, 'updated_at' => TEST_NOW]);
            $sourceId++;
        }
    }
    insertFixture('attendance_daily_schedules', ['person_key'=>'open:ou_7',
        'work_date'=>$date, 'need_punch'=>0, 'group_name'=>'休息组']);
    $logId = 1;
    foreach ([$date, $today] as $logDate) {
        for ($i=1; $i<=9; $i++) {
            for ($j=0; $j<$i; $j++) {
                insertFixture('logs', ['id'=>$logId++, 'passusername'=>sprintf('员工%02d',$i), 'passusertype'=>'员工',
                    'passdoor'=>$j%3 ? 'B7':'B8', 'cardid'=>str_pad((string)$i,10,'0',STR_PAD_LEFT),
                    'action'=>$j%4 ? '开门成功':'开门失败：没有权限', 'time'=>strtotime($logDate.' 09:20:00')+$i*60+$j]);
            }
        }
        foreach (['123','0000000123','ABC','1234567890','0000000000','0000000012'] as $card) {
            insertFixture('logs',['id'=>$logId++,'passusername'=>'边界','passusertype'=>'学员','passdoor'=>'B7','cardid'=>$card,
                'action'=>'开门失败','time'=>strtotime($logDate.' 00:00:00')+$logId]);
        }
        insertFixture('logs',['id'=>$logId++,'passusername'=>'系统','passusertype'=>'管理员','passdoor'=>'系统','cardid'=>'',
            'action'=>'维护','time'=>strtotime($logDate.' 23:59:59')]);
    }
    $indexResult = PerformanceIndexes::run(true);
    checkResult($indexResult['ok'], 'Online index deployment failed: ' . json_encode($indexResult));
    $repeat = PerformanceIndexes::run(true);
    checkResult($repeat['ok'] && count(array_filter($repeat['indexes'], function($item){return $item['status']!=='present';}))===0,
        'Index deployment must be idempotent');

    foreach (['earliest','latest'] as $rule) {
        Settings::set('attendance_pair_effective_time_rule', $rule);
        $conn->query('TRUNCATE attendance_effective_records'); $conn->query('TRUNCATE attendance_daily_reports');
        $before = snapshot($baseline,'calculate',['date'=>$date]);
        $conn->query('TRUNCATE attendance_effective_records'); $conn->query('TRUNCATE attendance_daily_reports');
        $after = snapshot(ROOT,'calculate',['date'=>$date]);
        checkResult($before === $after, 'Daily calculation changed for ' . $rule);
        echo "Equivalent: complete daily calculations ({$rule})\n";
    }
    equivalent('reports',['date_from'=>$date,'date_to'=>$today,'keyword'=>''],'all report filters, trace and export');
    equivalent('reports',['date_from'=>$date,'date_to'=>$today,'keyword'=>'员工01'],'report keyword search');
    equivalent('reports',['date_from'=>$emptyDate,'date_to'=>$emptyDate,'keyword'=>''],'empty report range');
    equivalent('counterparts',[],'pairing/reminder/effective checks for every source');
    equivalent('dedup',['events'=>[['0000000001',strtotime($date.' 09:25:00'),'工牌-B7','event_1'],
        ['0000000001',strtotime($date.' 09:25:00'),'工牌-B7','log:other'],
        ['0000000001',strtotime($date.' 09:25:01'),'工牌-B7','new_event']]],'historical badge deduplication');
    foreach ([['start_date'=>$date,'end_date'=>$date], ['start_date'=>$date,'end_date'=>$today,'action_type'=>'success'],
        ['start_date'=>$today,'end_date'=>$today,'action_type'=>'failed','q'=>'B7'],
        ['start_date'=>$emptyDate,'end_date'=>$emptyDate]] as $params) {
        equivalent('Modules/dashboard.php',$params,'dashboard totals, all 9 grains, peaks, rankings and unswiped list');
    }
    equivalent('Modules/attendance.php',['date_from'=>$date,'date_to'=>$today],'attendance page summary/pagination/rows');
    equivalent('Modules/accesslog.php',['q'=>'B7'],'access log search');
    equivalent('Pages/userpanel.php',['date'=>$date],'personal log date filtering');
    insertFixture('access_roles',['name'=>'fixture_role']);
    $roleId=intval($conn->insert_id);
    foreach ([['employee','ou_1'],['employee','missing_employee'],['learner','S0'],['learner','missing_learner'],
        ['guest','missing_guest'],['unknown_kind','unknown_subject']] as [$kind,$subject]) {
        insertFixture('access_role_members',['role_id'=>$roleId,'member_kind'=>$kind,'employee_open_id'=>$subject]);
    }
    equivalent('Modules/role.php',[],'role member lists and counts without request-time deletes');
    require_once ROOT . '/Core/RuntimeMaintenance.php';
    $cursorFile=ROOT . '/tmp/doorlock_role_cleanup.json';
    $savedCursor=is_file($cursorFile) ? file_get_contents($cursorFile) : null;
    try {
        $cleanup=RuntimeMaintenance::deleteOrphanRoleMembers(100);
        checkResult($cleanup['scanned']<=100 && $cleanup['deleted']===3,'bounded role cleanup must only delete dangling known kinds');
        checkResult(count(sqlRows("SELECT id FROM access_role_members WHERE member_kind='unknown_kind'"))===1,'unknown legacy role kinds must remain intact');
    } finally {
        if ($savedCursor!==null) { file_put_contents($cursorFile,$savedCursor); }
        elseif (is_file($cursorFile)) { unlink($cursorFile); }
    }

    $edgeIds=[];
    foreach ([null,'','garbage','-1','0','1','00001',' 1','1.5','1e3','10000000000','9999999999'] as $legacyTime) {
        insertFixture('logs',['time'=>$legacyTime]); $edgeIds[]=intval($conn->insert_id);
    }
    foreach ([[-2,2],[0,1000],[1,1],[9999999998,10000000001]] as [$from,$to]) {
        foreach ([true,false] as $inclusive) {
            $old=$inclusive ? "`time` BETWEEN {$from} AND {$to}" : "`time`>={$from} AND `time`<{$to}";
            $new=Database::logTimeRange($from,$to);
            if (!$inclusive) { $new=Database::logTimeRange($from,$to,'',false); }
            checkResult(sqlRows("SELECT id FROM logs WHERE {$old} ORDER BY id")===sqlRows("SELECT id FROM logs WHERE {$new} ORDER BY id"),
                'numeric time index must preserve malformed and legacy time comparisons');
        }
    }
    $conn->query('DELETE FROM logs WHERE id IN ('.implode(',',$edgeIds).')');
    require __DIR__ . '/archive_regression_cases.php';
    require __DIR__ . '/message_regression_cases.php';
    echo "Passed {$checks} MySQL regression checks.\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$database}`");
}
