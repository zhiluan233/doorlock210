<?php
namespace anim210System;
if (!defined('ROOT') || !isset($conn,$checks)) { exit(2); }
// Test transport only: these checks never contact Feishu.
class appLinkFeishu {
    public static $fail=true;
    public static $uuids=[];
    public function __construct($mode) {}
    public function sendInteractiveMessage($openId,$card,$uuid) {
        self::$uuids[]=$uuid;
        return self::$fail ? ['ok'=>false,'message'=>'injected transport failure'] : ['ok'=>true,'data'=>['message_id'=>'test_message']];
    }
}
$event=hash('sha256','message_regression');
insertFixture('attendance_queue',['event_hash'=>$event,'employee_open_id'=>'ou_1','employee_name'=>'fixture',
    'punch_time'=>TEST_NOW,'punch_time_text'=>date('Y-m-d H:i:s',TEST_NOW),'need_message'=>1,
    'message_status'=>'pending','created_at'=>TEST_NOW,'updated_at'=>TEST_NOW]);
$other=new \mysqli('localhost','root','',$database,0,$socket);
try {
    $other->query("SELECT GET_LOCK(CONCAT('doorlock_swipe_messages:',SHA1(DATABASE())),0)");
    $result=AttendanceService::processMessageQueue(1,$event);
    checkResult(!empty($result['busy']) && count(appLinkFeishu::$uuids)===0,'cron and immediate worker must never send the same pending batch concurrently');
    $other->query("SELECT RELEASE_LOCK(CONCAT('doorlock_swipe_messages:',SHA1(DATABASE())))");
    $result=AttendanceService::processMessageQueue(1,$event);
    $row=sqlRows("SELECT * FROM attendance_queue WHERE event_hash='{$event}'")[0];
    checkResult($result['failed']===1 && $row['message_status']==='failed' && intval($row['message_next_retry'])>TEST_NOW,'transport failures must remain in the durable retry queue');
    checkResult(AttendanceService::processMessageQueue(1,$event)['total']===0,'failed messages must respect their retry deadline');
    $conn->query("UPDATE attendance_queue SET message_next_retry=0 WHERE event_hash='{$event}'");
    appLinkFeishu::$fail=false;
    $result=AttendanceService::processMessageQueue(1,$event);
    checkResult($result['sent']===1 && sqlRows("SELECT message_status FROM attendance_queue WHERE event_hash='{$event}'")[0]['message_status']==='sent','durable messages must recover after worker or transport failure');
    checkResult(count(array_unique(appLinkFeishu::$uuids))===1,'retries must reuse the same Feishu idempotency identifier');
} finally { $other->close(); }
echo "Verified: concurrent message consumers, durable retries and idempotency identifiers\n";
