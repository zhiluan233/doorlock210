<?php
// php tests/performance_regression.php — isolated SQL test double; no database connection.
namespace anim210System;

define('ROOT', dirname(__DIR__));
date_default_timezone_set('Asia/Shanghai');

class TestRows {
    public $rows;
    public function __construct($rows) { $this->rows = $rows; }
}

function resetDatabase($version = Migrator::SCHEMA_VERSION)
{
    $GLOBALS['state'] = [
        'settings' => ['schema_version' => $version, 'attendance_module_enabled' => 'true'],
        'queries' => [], 'lookups' => [], 'inserts' => [], 'error' => '',
        'lock' => 1, 'race' => false, 'failure' => '', 'throw' => false,
        'lookup' => null
    ];
    Settings::invalidate();
}

function mysqli_query($conn, $sql)
{
    $state = &$GLOBALS['state'];
    $state['queries'][] = $sql;
    $state['error'] = '';
    if ($state['failure'] !== '' && strpos($sql, $state['failure']) !== false) {
        if ($state['throw']) {
            throw new \mysqli_sql_exception('test query failure');
        }
        $state['error'] = 'test query failure';
        return false;
    }
    if (strpos($sql, 'SELECT GET_LOCK') === 0) {
        if ($state['race']) {
            $state['settings']['schema_version'] = Migrator::SCHEMA_VERSION;
        }
        return new TestRows([['acquired' => $state['lock']]]);
    }
    if (strpos($sql, 'SHOW ') === 0) {
        return new TestRows([['Type' => 'varchar(128)']]);
    }
    if (strpos($sql, 'SELECT `setting_key`, `setting_value`') === 0) {
        $rows = [];
        foreach ($state['settings'] as $key => $value) {
            $rows[] = ['setting_key' => $key, 'setting_value' => $value];
        }
        return new TestRows($rows);
    }
    if (strpos($sql, 'INSERT INTO `system_settings`') === 0 && strpos($sql, "'schema_version'") !== false) {
        $state['settings']['schema_version'] = Migrator::SCHEMA_VERSION;
    }
    return true;
}
function mysqli_fetch_assoc($rs) { return array_shift($rs->rows); }
function mysqli_num_rows($rs) { return count($rs->rows); }
function mysqli_free_result($rs) {}
function mysqli_real_escape_string($conn, $value) { return addslashes((string)$value); }
function mysqli_error($conn) { return $GLOBALS['state']['error']; }
function mysqli_insert_id($conn) { return 37; }

class Database {
    public static function escape($value) { return addslashes((string)$value); }
    public static function insert($table, $data) {
        $GLOBALS['state']['inserts'][] = [$table, $data];
        return true;
    }
    public static function querySingleLine($table, $query, $raw = false) {
        $GLOBALS['state']['lookups'][] = [$table, $query];
        $lookup = $GLOBALS['state']['lookup'];
        return $lookup ? $lookup($table, $query) : null;
    }
}

require ROOT . '/Core/Settings.php';
require ROOT . '/Core/Migrator.php';
require ROOT . '/Middleware/Class.Attendance.php';
require ROOT . '/Middleware/Class.AttendanceModule.php';

$conn = new \stdClass();
$_config = ['feishu' => ['keyConfigFile' => __FILE__]];
$checks = 0;
function check($condition, $message) {
    if (!$condition) { throw new \RuntimeException($message); }
    $GLOBALS['checks']++;
}
function callPrivate($method, ...$args) {
    $reflection = new \ReflectionMethod(AttendanceModuleService::class, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null, ...$args);
}
function matchingQueries($needle) {
    return array_values(array_filter($GLOBALS['state']['queries'], function ($sql) use ($needle) {
        return strpos($sql, $needle) !== false;
    }));
}
function sourceWrites() {
    $writes = [];
    foreach (matchingQueries('INSERT INTO `attendance_source_records`') as $sql) {
        preg_match('/\((.*?)\) VALUES \((.*?)\) ON DUPLICATE KEY UPDATE /s', $sql, $match);
        $columns = array_map(function ($column) { return trim($column, '`'); }, explode(',', $match[1]));
        $values = array_map('stripslashes', str_getcsv($match[2], ',', "'", '\\'));
        $writes[] = array_combine($columns, $values);
    }
    return $writes;
}

resetDatabase();
check(Migrator::ensure()['ok'], 'current schema must succeed');
check(count(matchingQueries('CREATE ')) === 0 && count(matchingQueries('UPDATE ')) === 0
    && count(matchingQueries('INSERT ')) === 0 && count(matchingQueries('DELETE ')) === 0,
    'normal requests must not run DDL or data migrations');

resetDatabase('old');
$GLOBALS['state']['lock'] = 0;
check(!Migrator::ensure()['ok'], 'busy migration must return immediately');
check(count(matchingQueries('CREATE ')) === 0, 'busy request must not start a second migration');

resetDatabase('old');
Settings::get('schema_version'); // populate stale per-process cache
$GLOBALS['state']['race'] = true;
check(Migrator::ensure()['ok'], 'migration completed by another connection must be observed');
check(count(matchingQueries('CREATE ')) === 0, 'version must be rechecked after acquiring lock');
check(count(matchingQueries('RELEASE_LOCK')) === 1, 'early success must release lock');

resetDatabase('old');
check(Migrator::ensure()['ok'], 'outdated schema must still migrate');
check($GLOBALS['state']['settings']['schema_version'] === Migrator::SCHEMA_VERSION, 'successful migration must save version');
check(count(matchingQueries('UPDATE `logs`')) === 0, 'even upgrades must not rewrite historical logs');
check(count(matchingQueries('RELEASE_LOCK')) === 1, 'successful upgrade must release lock');

resetDatabase('old');
$GLOBALS['state']['failure'] = 'INSERT IGNORE INTO `system_settings`';
check(!Migrator::ensure()['ok'], 'default-setting errors must propagate');
check($GLOBALS['state']['settings']['schema_version'] === 'old', 'failed migrations must remain retryable');
check(count(matchingQueries('RELEASE_LOCK')) === 1, 'failed upgrade must release lock');

resetDatabase('old');
$GLOBALS['state']['failure'] = 'CREATE TABLE';
$GLOBALS['state']['throw'] = true;
check(!Migrator::ensure()['ok'], 'strict mysqli exceptions must be reported');
check(count(matchingQueries('RELEASE_LOCK')) === 1, 'exceptions must release migration lock');

resetDatabase();
foreach (['123' => '0000000123', '1234567890' => '1234567890', '' => '', 'ABC' => 'ABC'] as $input => $expected) {
    AttendanceService::writeAccessLog('test', 'employee', 'door', (string)$input, 'success', 1793064600);
    $last = end($GLOBALS['state']['inserts']);
    check($last[1]['cardid'] === $expected, 'card number must be normalized at write boundary');
}

$employee = ['id' => 1, 'open_id' => 'ou_test', 'user_id' => 'u_test', 'employee_id' => 'E001', 'name' => 'test'];
$device = ['id' => 7, 'name' => 'door', 'did' => 'sn1', 'allowedEmployee' => str_repeat('large_acl', 10000),
    'mqtt_password' => 'private_password'];
AttendanceModuleService::ingestBadgeSwipe($employee, $device, '1234567890', 1793064600);
$writes = sourceWrites();
$write = end($writes);
$payload = json_decode($write['raw_payload'], true, 512, JSON_THROW_ON_ERROR);
check(strlen($write['raw_payload']) < 1000, 'badge payload must not grow with device ACL size');
check(!isset($payload['device']['allowedEmployee']) && !isset($payload['device']['mqtt_password']), 'device configuration must stay out of source snapshot');
check($payload['device']['id'] === 7 && $payload['employee']['open_id'] === 'ou_test', 'source identity must be retained');

resetDatabase();
$batch = ['employee_no' => 'E001', 'records' => [
    ['record_id' => 'flow_a', 'check_time' => 1793064600, 'type' => 7, 'location_name' => 'office_a'],
    ['record_id' => 'flow_b', 'check_time' => 1793064700, 'type' => 8, 'location_name' => 'office_b']
]];
foreach (callPrivate('flowRecordsFromQueryResult', $batch) as $record) {
    callPrivate('ingestFeishuFlowRecord', $record, $batch);
}
$writes = sourceWrites();
check(count($writes) === 2, 'batch must ingest both records');
foreach ($writes as $index => $write) {
    $payload = json_decode($write['raw_payload'], true, 512, JSON_THROW_ON_ERROR);
    check($payload === $batch, 'original flow context must stay available for legacy runtime repairs');
    check($payload['employee_no'] === 'E001' && intval($write['flow_type']) === 7 + $index, 'parent identity and flow type must survive');
}

resetDatabase();
$eventRecords = array_map(function ($record) {
    $record['employee_no'] = 'E001';
    return $record;
}, $batch['records']);
AttendanceModuleService::handleFeishuEvent(['header' => ['event_type' => 'attendance.user_flow.created_v1'],
    'event' => ['records' => $eventRecords]], 'attendance.user_flow.created_v1', 'event_test');
$writes = sourceWrites();
check(count($writes) === 2, 'webhook must ingest each flow');
foreach ($writes as $write) {
    $payload = json_decode($write['raw_payload'], true, 512, JSON_THROW_ON_ERROR);
    check(isset($payload['event']['records']) && $write['event_id']==='event_test', 'webhook context and event ID must be retained');
}

foreach (['open:ou_test' => "`employee_open_id`='ou_test'", 'no:E001' => "`employee_no`='E001'",
    'user:u_test' => "`employee_user_id`='u_test'", 'bad:value' => '1=0', '' => '1=0'] as $key => $where) {
    check(callPrivate('sourcePersonWhere', $key) === $where, 'person queries must directly compare the correct indexed column');
}
check(callPrivate('sourcePersonWhere', "open:a'b:c") === "`employee_open_id`='a\\'b:c'", 'person lookup must escape values and retain embedded colons');

resetDatabase();
callPrivate('sourceRecordHasCounterpart', ['id' => 1, 'employee_open_id' => 'ou_test',
    'source_kind' => 'badge', 'punch_time' => 1793064600], 300);
$lookups = $GLOBALS['state']['lookups'];
$sql = $lookups[0][1];
check(strpos($sql, "`employee_open_id`='ou_test'") !== false && strpos($sql, 'CONCAT') === false, 'counterpart lookup must use the employee index');
check(strpos($sql, 'BETWEEN 1793064300 AND 1793064900') !== false, 'counterpart time window must stay unchanged');

resetDatabase();
$GLOBALS['state']['lookup'] = function ($table, $query) { return ['id' => 12]; };
check(callPrivate('localBadgeSourceExists', $employee, '1234567890', 1793064600, 'door', 'log:12'), 'existing external ID must deduplicate');
check(count($GLOBALS['state']['lookups']) === 1, 'external ID hit must avoid fallback scans');

resetDatabase();
$GLOBALS['state']['lookup'] = function ($table, $query) {
    return strpos($query, "`employee_no`='E001'") !== false ? ['id' => 12] : null;
};
check(callPrivate('localBadgeSourceExists', $employee, '1234567890', 1793064600, 'door', 'log:12'), 'fallback employee identity must still deduplicate');
check(count($GLOBALS['state']['lookups']) === 3, 'fallback should stop at the first matching identity');
foreach ($GLOBALS['state']['lookups'] as [$table, $sql]) {
    check(strpos($sql, ' OR ') === false, 'deduplication must not combine unique ID and identity with OR');
}
resetDatabase();
check(!callPrivate('localBadgeSourceExists', $employee, '1234567890', 1793064600, 'door', 'log:12'), 'new events must not be mistaken for duplicates');

echo "Passed {$checks} performance regression checks.\n";
