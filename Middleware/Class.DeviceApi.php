<?php

/*

设备对接模块
Ver 1.0.0.0 20240705
Code by Jason

*/

namespace anim210System;

use anim210System;

class deviceApi {

    private static $deviceColumns = null;

    public function deviceMethod($params) 
    {
        Header("Content-Type: application/json");
        error_reporting(E_ALL & ~E_WARNING);
        global $_config;

        if(isset($params['method']) && preg_match("/^[A-Za-z0-9\_\-]{1,30}$/", $params['method'])) {
            $jsonData = file_get_contents('php://input');
            $data = json_decode($jsonData,true);
            if ($data === null) {
                // 解码失败
                http_response_code(400);
			    exit("invalid post data");
            }
            $requestContext = $this->normalizeDeviceRequest($data);
            $devicePayload = $requestContext['payload'];
            $method = $this->resolveDeviceMethod($params['method'], $requestContext);
			switch($method) {
                case "heartBeat": 
                    $serial = $this->payloadValue($devicePayload, ['Serial', 'serial', 'ID']);
                    $ip = $this->payloadValue($devicePayload, ['IP', 'ip']);
                    $mac = $this->payloadValue($devicePayload, ['MAC', 'mac']);
                    $now = $this->normalizeHeartbeatTime($this->payloadValue($devicePayload, ['Now', 'now', 'Time', 'time']));
                    $key = $this->heartbeatKey($devicePayload, $requestContext);
                    $oem = $this->payloadValue($devicePayload, ['OEM', 'oem']);
                    $model = $this->payloadValue($devicePayload, ['Model', 'model']);
                    $controllerType = $this->detectControllerType($requestContext, $devicePayload);
                    if ($serial !== '' && $ip !== '' && $mac !== '' && $now !== '') {
                        if (empty($_SERVER['REMOTE_ADDR'])) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        if ($_SERVER['REMOTE_ADDR'] !== $ip) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        $deviceInfo = Database::querySingleLine("devices", Array("ip" => $ip));
                        if ($deviceInfo == null) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        $updatedata = Array(
                            "did"      => $serial,
                            "mac"      => $mac,
                            "hbtime"   => $now
                        );
                        $this->addDeviceUpdateField($updatedata, 'serial', $serial);
                        if ($model !== '') {
                            $this->addDeviceUpdateField($updatedata, 'model', $model);
                        }
                        if ($controllerType !== '') {
                            $this->addDeviceUpdateField($updatedata, 'controller_type', $controllerType);
                        }
                        if ($key !== '') {
                            $updatedata["apikey"] = $key;
                        }
                        $responseOem = trim((string)($deviceInfo['oemcode'] ?? ''));
                        if ($responseOem === '' && $oem !== '') {
                            $updatedata["oemcode"] = $oem;
                            $responseOem = $oem;
                        }
                        $update = Database::update("devices", $updatedata, Array("id" => $deviceInfo['id']));
                        if($update !== true) {
                            Header("HTTP/1.1 500 Internal Error");
                            exit("[U]更新数据库时遇到错误，请联系管理员");
                        }
                        $resp = [
                            'Key' => $key !== '' ? $key : (string)($deviceInfo['apikey'] ?? ''),
                            'OEM' => $responseOem
                        ];
                        http_response_code(200);
			            $this->exitDeviceJson($resp, $requestContext);
                    } else {
                        http_response_code(400);
			            exit("uncomplete params");
                    }
                break;
                case "verifyCard": 
                    $serial = $this->payloadValue($devicePayload, ['Serial', 'serial', 'ID']);
                    $ip = $this->payloadValue($devicePayload, ['IP', 'ip']);
                    $mac = $this->payloadValue($devicePayload, ['MAC', 'mac']);
                    $rawCard = $this->payloadValue($devicePayload, ['Card', 'card', 'CardNo', 'cardNo', 'CardID', 'cardId', 'card_id']);
                    $eventOnlyResponse = $this->eventOnlyResponse($devicePayload, $requestContext);
                    if ($serial !== '' && $rawCard === '' && $eventOnlyResponse !== null) {
                        if (empty($_SERVER['REMOTE_ADDR'])) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        $deviceInfo = $this->findVerifyDevice($serial, $ip, $mac);
                        if ($deviceInfo == null) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        if ($_SERVER['REMOTE_ADDR'] !== $deviceInfo['ip']) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        http_response_code(200);
                        $this->exitDeviceJson($eventOnlyResponse, $requestContext);
                    }
                    if ($serial !== '' && $rawCard !== '') {
                        if (empty($_SERVER['REMOTE_ADDR'])) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        $deviceInfo = $this->findVerifyDevice($serial, $ip, $mac);
                        if ($deviceInfo == null) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }
                        if ($_SERVER['REMOTE_ADDR'] !== $deviceInfo['ip']) {
                            http_response_code(403);
			                exit("Unauthorized device!");
                        }

                        $eventTime = $this->deviceEventTimestamp($this->payloadValue($devicePayload, ['Time', 'time', 'Now', 'now']));
                        $card = (string)$rawCard;
                        if (!ctype_digit($card) && $this->is_base64($card)) {
                            $card = base64_decode($card);
                        }
                        $card = AttendanceService::normalizeCardNumber($card);

                        $employeeInfo = Database::querySingleLine("employee", Array("card_id" => $card));
                        $learnerInfo = Database::querySingleLine("learner", Array("card_id" => $card));
                        $guestInfo = Database::querySingleLine("guest", Array("card_id" => $card));

                        if ($guestInfo != null) {
                            $reason = '';
                            $allowPass = AttendanceService::canGuestPass($guestInfo, $deviceInfo, $reason);
                            if ($allowPass === false) {
                                http_response_code(200);
                                $resp = $this->cardResponse($deviceInfo, $devicePayload, '0', $requestContext);
                                AttendanceService::writeAccessLog($guestInfo['name'], '访客', $deviceInfo['name'], $card, '开门失败：'.$reason, $eventTime);
                                $this->exitDeviceJson($resp, $requestContext);
                            }
                                http_response_code(200);
                                $resp = $this->cardResponse($deviceInfo, $devicePayload, '1', $requestContext);

                                AttendanceService::writeAccessLog($guestInfo['name'], '访客', $deviceInfo['name'], $card, '开门成功', $eventTime);
                                $this->exitDeviceJson($resp, $requestContext);
                        }

                        if ($learnerInfo != null) {
                            $reason = '';
                            $allowPass = AttendanceService::canLearnerPass($learnerInfo, $deviceInfo, $reason);
                            if ($allowPass === false) {
                                http_response_code(200);
                                $resp = $this->cardResponse($deviceInfo, $devicePayload, '0', $requestContext);
                                AttendanceService::writeAccessLog($learnerInfo['name'], '学员', $deviceInfo['name'], $card, '开门失败：'.$reason, $eventTime);
                                $this->exitDeviceJson($resp, $requestContext);
                            }
                                http_response_code(200);
                                $resp = $this->cardResponse($deviceInfo, $devicePayload, '1', $requestContext);

                                AttendanceService::writeAccessLog($learnerInfo['name'], '学员', $deviceInfo['name'], $card, '开门成功', $eventTime);
                                $this->exitDeviceJson($resp, $requestContext);
                        }

                        if ($employeeInfo != null) {
                            $reason = '';
                            $allowPass = AttendanceService::canEmployeePass($employeeInfo, $deviceInfo, $reason);
                            if ($allowPass === false) {
                                http_response_code(200);
                                $resp = $this->cardResponse($deviceInfo, $devicePayload, '0', $requestContext);
                                AttendanceService::writeAccessLog($employeeInfo['name'], '员工', $deviceInfo['name'], $card, '开门失败：'.$reason, $eventTime);
                                $this->exitDeviceJson($resp, $requestContext);
                            }
                                http_response_code(200);
                                $resp = $this->cardResponse($deviceInfo, $devicePayload, '1', $requestContext);
                                AttendanceService::writeAccessLog($employeeInfo['name'], '员工', $deviceInfo['name'], $card, '开门成功', $eventTime);
                                AttendanceService::enqueueSwipe($employeeInfo, $deviceInfo, $card, 'card', $eventTime);
                                $this->exitDeviceJson($resp, $requestContext);
                        }

                        http_response_code(200);
                        $resp = $this->cardResponse($deviceInfo, $devicePayload, '0', $requestContext);
			            $this->exitDeviceJson($resp, $requestContext);
                    } else {
                        http_response_code(400);
			            exit("uncomplete params");
                    }
                break;
				default:
					Header("HTTP/1.1 404 Not Found");
					exit("Undefined method {$params['method']}");
			}
		} else {
            Header("HTTP/1.1 400 Bad Request");
			exit("Illegal method.");
        }
    }

    private function normalizeDeviceRequest($data)
    {
        $payload = $data;
        $wrapped = false;
        if (isset($data['data']) && is_array($data['data'])) {
            $payload = $data['data'];
            $wrapped = isset($data['method']) || isset($data['taskNo']) || isset($data['version']);
        }

        return [
            'payload' => is_array($payload) ? $payload : [],
            'wrapped' => $wrapped,
            'id' => $data['id'] ?? null,
            'taskNo' => $data['taskNo'] ?? null,
            'version' => (string)($data['version'] ?? ''),
            'method' => (string)($data['method'] ?? '')
        ];
    }

    private function resolveDeviceMethod($queryMethod, $requestContext)
    {
        $method = $this->normalizeDeviceMethodName((string)$queryMethod);
        if ($method !== '') {
            return $method;
        }
        return $this->normalizeDeviceMethodName((string)($requestContext['method'] ?? ''));
    }

    private function normalizeDeviceMethodName($method)
    {
        $method = strtolower(trim((string)$method));
        if (in_array($method, ['heartbeat', 'heart_beat', 'status'], true)) {
            return 'heartBeat';
        }
        if (in_array($method, ['verifycard', 'verify_card', 'cardverify', 'card_verify', 'verify', 'card', 'cardevent', 'card_event', 'alarmevent', 'alarm_event'], true)) {
            return 'verifyCard';
        }
        return '';
    }

    private function payloadValue($payload, $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                $value = $payload[$key];
                if (is_array($value) || is_object($value)) {
                    continue;
                }
                return trim((string)$value);
            }
        }
        return '';
    }

    private function heartbeatKey($payload, $requestContext)
    {
        if (isset($requestContext['taskNo']) && $requestContext['taskNo'] !== null && $requestContext['taskNo'] !== '') {
            return trim((string)$requestContext['taskNo']);
        }
        return $this->payloadValue($payload, ['Key', 'key']);
    }

    private function normalizeHeartbeatTime($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\d*$/', $value, $matches)) {
            return sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                intval($matches[1]),
                intval($matches[2]),
                intval($matches[3]),
                intval($matches[4]),
                intval($matches[5]),
                intval($matches[6])
            );
        }
        return $value;
    }

    private function deviceEventTimestamp($value)
    {
        $timeText = $this->normalizeHeartbeatTime($value);
        if ($timeText === '') {
            return time();
        }
        try {
            $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timeText, new \DateTimeZone($this->systemTimezone()));
            if ($dateTime instanceof \DateTimeImmutable) {
                return $dateTime->getTimestamp();
            }
        } catch (\Exception $e) {
            // Fall back to strtotime below.
        }
        $timestamp = strtotime($timeText);
        return $timestamp !== false && $timestamp > 0 ? $timestamp : time();
    }

    private function cardResponse($deviceInfo, $payload, $acsRes, $requestContext)
    {
        global $_config;

        $eventAck = $this->cardEventAckFields($payload, $requestContext);
        if ($this->isHistoryOnlyCardEvent($payload, $requestContext, $eventAck)) {
            return $eventAck;
        }

        $response = [
            'ActIndex' => $this->cardActionIndex($payload, $requestContext),
            'AcsRes' => (string)$acsRes,
            'Time' => (string)$_config['doorOpenTime'],
            'OEM' => (string)($deviceInfo['oemcode'] ?? '')
        ];

        if ($eventAck !== null) {
            $response = array_merge($response, $eventAck);
        }

        return $response;
    }

    private function cardEventAckFields($payload, $requestContext)
    {
        $type = $this->payloadValue($payload, ['type', 'Type']);
        $indexAlarm = $this->payloadValue($payload, ['IndexAlarm', 'indexAlarm', 'index_alarm']);
        if ($type === '101' && $this->isValidEventIndex($indexAlarm)) {
            return ['IndexAlarm' => $indexAlarm];
        }

        $indexEvent = $this->payloadValue($payload, ['IndexEvent', 'indexEvent', 'index_event']);
        if ($type === '100' && $this->isValidEventIndex($indexEvent)) {
            return ['IndexEvent' => $indexEvent];
        }

        $method = strtolower((string)($requestContext['method'] ?? ''));
        $index = $this->payloadValue($payload, ['Index', 'index']);
        $eventType = $this->payloadValue($payload, ['EventType', 'eventType', 'event_type']);
        $eventCount = $this->payloadValue($payload, ['Count', 'count', 'EventCnt', 'eventCnt', 'event_cnt']);
        if (!empty($requestContext['wrapped']) && $method === 'alarmevent' && $eventType !== '' && $eventCount !== '' && $this->isValidEventIndex($index)) {
            return [
                'Index' => $index,
                'IndexAlarm' => $index
            ];
        }
        if (!empty($requestContext['wrapped']) && $method === 'cardevent' && $eventType !== '' && $eventCount !== '' && $this->isValidEventIndex($index)) {
            return [
                'Index' => $index,
                'IndexEvent' => $index
            ];
        }

        return null;
    }

    private function eventOnlyResponse($payload, $requestContext)
    {
        $eventAck = $this->cardEventAckFields($payload, $requestContext);
        if ($eventAck === null) {
            return null;
        }

        $method = strtolower((string)($requestContext['method'] ?? ''));
        $type = $this->payloadValue($payload, ['type', 'Type']);
        if ($method === 'alarmevent' || $type === '101') {
            return $eventAck;
        }

        return null;
    }

    private function isHistoryOnlyCardEvent($payload, $requestContext, $eventAck)
    {
        if ($eventAck === null) {
            return false;
        }

        $type = $this->payloadValue($payload, ['type', 'Type']);
        if ($type === '100' || $type === '101') {
            return true;
        }

        if (empty($requestContext['wrapped']) || strtolower((string)($requestContext['method'] ?? '')) !== 'cardevent') {
            return false;
        }

        $eventCount = $this->payloadValue($payload, ['Count', 'count', 'EventCnt', 'eventCnt', 'event_cnt']);
        if ($eventCount !== '' && ctype_digit($eventCount) && intval($eventCount) > 1) {
            return true;
        }

        return false;
    }

    private function cardActionIndex($payload, $requestContext)
    {
        if (!empty($requestContext['wrapped']) && strtolower((string)($requestContext['method'] ?? '')) === 'cardevent') {
            $reader = $this->payloadValue($payload, ['Reader', 'reader']);
            if ($reader !== '' && ctype_digit($reader)) {
                return (string)(intval($reader) & 1);
            }
            $door = $this->payloadValue($payload, ['Door', 'door']);
            if ($door !== '' && ctype_digit($door)) {
                return (string)max(0, intval($door) - 1);
            }
        }
        return '0';
    }

    private function isValidEventIndex($index)
    {
        return $index !== '' && preg_match('/^[0-9]{1,10}$/', (string)$index);
    }

    private function detectControllerType($requestContext, $payload)
    {
        if (!empty($requestContext['wrapped'])) {
            return 'single_door';
        }

        $model = strtolower($this->payloadValue($payload, ['Model', 'model']));
        if ($model !== '' && (strpos($model, 'g-1000') !== false || strpos($model, 'single') !== false || strpos($model, 'd110') !== false)) {
            return 'single_door';
        }

        return 'cloud_plus';
    }

    private function systemTimezone()
    {
        global $_config;

        $timezone = trim((string)($_config['timezone'] ?? 'Asia/Shanghai'));
        if ($timezone === '') {
            return 'Asia/Shanghai';
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            return 'Asia/Shanghai';
        }
        return $timezone;
    }

    private function addDeviceUpdateField(&$updatedata, $column, $value)
    {
        if ($value !== '' && $this->deviceColumnExists($column)) {
            $updatedata[$column] = $value;
        }
    }

    private function deviceColumnExists($column)
    {
        global $conn;
        if (self::$deviceColumns === null) {
            self::$deviceColumns = [];
            $rs = mysqli_query($conn, "SHOW COLUMNS FROM `devices`");
            if ($rs instanceof \mysqli_result) {
                while ($row = mysqli_fetch_assoc($rs)) {
                    self::$deviceColumns[$row['Field']] = true;
                }
                mysqli_free_result($rs);
            }
        }
        return isset(self::$deviceColumns[$column]);
    }

    private function findVerifyDevice($serial, $ip, $mac)
    {
        $deviceInfo = null;
        if ($serial !== '') {
            $deviceInfo = Database::querySingleLine("devices", Array("did" => $serial));
        }
        if ($deviceInfo == null && $serial !== '' && $this->deviceColumnExists('serial')) {
            $deviceInfo = Database::querySingleLine("devices", Array("serial" => $serial));
        }
        if ($deviceInfo == null && $ip !== '') {
            $deviceInfo = Database::querySingleLine("devices", Array("ip" => $ip));
        }
        if ($deviceInfo == null && $mac !== '') {
            $deviceInfo = Database::querySingleLine("devices", Array("mac" => $mac));
        }
        return $deviceInfo;
    }

    private function exitDeviceJson($payload, $requestContext)
    {
        if (!empty($requestContext['wrapped'])) {
            $response = [
                'id' => $requestContext['id'],
                'taskNo' => $requestContext['taskNo'],
                'version' => $requestContext['version'] !== '' ? $requestContext['version'] : '1.0',
                'method' => $requestContext['method'] !== '' ? $requestContext['method'] : '',
                'data' => $payload
            ];
            exit(json_encode($response, JSON_UNESCAPED_UNICODE));
        }
        exit(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function is_base64($string) {
        // 检查字符串是否为空
        if (empty($string)) {
            return false;
        }
    
        // 检查字符串长度是否是4的倍数
        if (strlen($string) % 4 !== 0) {
            return false;
        }
    
            // 使用正则表达式匹配Base64编码的模式
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string)) {
            return false;
        }

        // 尝试解码并检查解码后的字符串是否是原始字符串的有效Base64编码
        $decoded = base64_decode($string, true);
        if ($decoded === false) {
            return false;
        }

        // 检查解码后的字符串是否重新编码为原始字符串
        if (base64_encode($decoded) !== $string) {
            return false;
        }

        return true;
    }
}
