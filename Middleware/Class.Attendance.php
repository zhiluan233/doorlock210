<?php
/*

门禁考勤队列与远程开门模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

class AttendanceService {

    public static function canEmployeePass($employeeInfo, $deviceInfo, &$reason = '')
    {
        if (!$employeeInfo) {
            $reason = '未找到员工';
            return false;
        }
        if (($employeeInfo['status'] ?? '') !== 'true') {
            $reason = '员工账号已禁用';
            return false;
        }

        $openId = $employeeInfo['open_id'] ?? '';
        if (self::matchLegacyList($deviceInfo['allowedEmployee'] ?? '', $openId)) {
            $reason = '员工名单权限';
            return true;
        }

        $policies = self::getPolicies($deviceInfo['id'], 'employee');
        foreach ($policies as $policy) {
            if (self::matchEmployeePolicy($policy, $employeeInfo)) {
                $reason = self::policyLabel($policy);
                return true;
            }
        }

        $reason = '没有权限';
        return false;
    }

    public static function canGuestPass($guestInfo, $deviceInfo, &$reason = '')
    {
        if (!$guestInfo) {
            $reason = '未找到访客';
            return false;
        }
        if (($guestInfo['status'] ?? '') !== 'true') {
            $reason = '访客已禁用';
            return false;
        }

        $openId = $guestInfo['open_id'] ?? '';
        if (self::matchLegacyList($deviceInfo['allowedGuest'] ?? '', $openId)) {
            $reason = '访客名单权限';
            return true;
        }

        $policies = self::getPolicies($deviceInfo['id'], 'guest');
        foreach ($policies as $policy) {
            if (($policy['subject_type'] ?? '') === 'all') {
                $reason = '全体访客';
                return true;
            }
            if (($policy['subject_type'] ?? '') === 'guest' && ($policy['subject_value'] ?? '') === $openId) {
                $reason = '访客名单权限';
                return true;
            }
        }

        $reason = '没有权限';
        return false;
    }

    public static function writeAccessLog($username, $userType, $doorName, $cardId, $action, $eventTime = null)
    {
        $eventTime = $eventTime ?: time();
        return Database::insert("logs", [
            'passusername' => $username,
            'passusertype' => $userType,
            'passdoor' => $doorName,
            'cardid' => $cardId,
            'action' => $action,
            'time' => $eventTime
        ]);
    }

    public static function enqueueSwipe($employeeInfo, $deviceInfo, $cardId, $source = 'card', $eventTime = null)
    {
        global $conn;

        $eventTime = $eventTime ?: time();
        $needOa = Settings::getBool('oa_attendance_enabled');
        $needFeishu = Settings::getBool('feishu_attendance_enabled') && Settings::getBool('card_as_attendance_enabled');
        $needMessage = Settings::getBool('feishu_message_enabled');

        if (!$needOa && !$needFeishu && !$needMessage) {
            return '';
        }

        $openId = $employeeInfo['open_id'] ?? '';
        $doorId = intval($deviceInfo['id'] ?? 0);
        $eventHash = hash('sha256', implode('|', [$source, $openId, $doorId, $cardId, $eventTime]));
        $eventTimeText = date('Y-m-d H:i:s', $eventTime);
        $location = Settings::get('oa_location_default', '公司门禁');
        if (!empty($deviceInfo['name'])) {
            $location = $deviceInfo['name'];
        }

        $now = time();
        $data = [
            'event_hash' => $eventHash,
            'source' => $source,
            'employee_open_id' => $openId,
            'employee_user_id' => $employeeInfo['user_id'] ?? '',
            'employee_no' => $employeeInfo['employee_id'] ?? '',
            'employee_name' => $employeeInfo['name'] ?? '',
            'door_id' => $doorId,
            'door_name' => $deviceInfo['name'] ?? '',
            'card_id' => $cardId,
            'punch_time' => $eventTime,
            'punch_time_text' => $eventTimeText,
            'location' => $location,
            'need_oa' => $needOa ? 1 : 0,
            'need_feishu' => $needFeishu ? 1 : 0,
            'need_message' => $needMessage ? 1 : 0,
            'oa_status' => $needOa ? 'pending' : 'skipped',
            'oa_next_retry' => 0,
            'feishu_status' => $needFeishu ? 'pending' : 'skipped',
            'feishu_next_retry' => 0,
            'message_status' => $needMessage ? 'pending' : 'skipped',
            'message_next_retry' => 0,
            'created_at' => $now,
            'updated_at' => $now
        ];

        $columns = [];
        $values = [];
        foreach ($data as $key => $value) {
            $columns[] = "`" . mysqli_real_escape_string($conn, $key) . "`";
            if ($value === null) {
                $values[] = "NULL";
            } else {
                $values[] = "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
            }
        }

        $sql = "INSERT IGNORE INTO `attendance_queue` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
        mysqli_query($conn, $sql);
        if ($needMessage) {
            self::scheduleAsyncMessage($eventHash);
        }
        return $eventHash;
    }

    public static function scheduleAsyncMessage($eventHash)
    {
        register_shutdown_function(function () use ($eventHash) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            AttendanceService::processMessageQueue(1, $eventHash);
        });
    }

    public static function processAllQueues()
    {
        $result = [
            'oa' => self::processOaQueue(Settings::getInt('oa_batch_size', 100)),
            'feishu' => self::processFeishuQueueCron(),
            'message' => self::processMessageQueue(Settings::getInt('feishu_message_batch_size', 50))
        ];
        return $result;
    }

    public static function processFeishuQueueCron()
    {
        $batchSize = min(50, max(1, Settings::getInt('feishu_attendance_batch_size', 50)));
        $maxBatches = min(100, max(1, Settings::getInt('feishu_attendance_cron_max_batches', 20)));
        $intervalMs = min(2000, max(0, Settings::getInt('feishu_attendance_batch_interval_ms', 100)));
        $summary = [
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'batches' => 0,
            'batch_size' => $batchSize,
            'max_batches' => $maxBatches,
            'limited' => false
        ];

        for ($i = 0; $i < $maxBatches; $i++) {
            $result = self::processFeishuQueue($batchSize);
            $total = intval($result['total'] ?? 0);
            if ($total === 0) {
                break;
            }

            $summary['total'] += $total;
            $summary['sent'] += intval($result['sent'] ?? 0);
            $summary['failed'] += intval($result['failed'] ?? 0);
            $summary['batches']++;

            if ($total < $batchSize) {
                break;
            }
            if ($i < $maxBatches - 1 && $intervalMs > 0) {
                usleep($intervalMs * 1000);
            }
        }

        if ($summary['batches'] >= $maxBatches) {
            $summary['limited'] = self::hasDueQueueRows('feishu');
        }

        return $summary;
    }

    public static function processOaQueue($limit = 100)
    {
        $rows = self::loadQueueRows('oa', $limit);
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $baseUrl = rtrim(Settings::get('oa_base_url', ''), '/');
        $appId = Settings::get('oa_app_id', '');
        $appSecret = Settings::get('oa_app_secret', '');
        if ($baseUrl === '' || $appId === '' || $appSecret === '') {
            self::markRowsFailed($rows, 'oa', 'OA配置不完整');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }

        $token = self::getOaToken($baseUrl, $appId, $appSecret);
        if ($token === '') {
            self::markRowsFailed($rows, 'oa', '无法获取OA token');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'employeeId' => $row['employee_open_id'],
                'punchCardDateTime' => $row['punch_time_text'],
                'location' => self::externalAttendanceLocation($row)
            ];
        }

        $url = $baseUrl . Settings::get('oa_upload_path', '/open/user/v1/badgeAttendance/upload');
        $resp = self::postJson($url, ['token' => $token, 'data' => $records], [], 20);
        $businessCode = intval($resp['response']['code'] ?? 0);
        if (($resp['status_code'] ?? 0) == 200 && $businessCode === 200) {
            self::markRowsSent($rows, 'oa', json_encode($resp['response'], JSON_UNESCAPED_UNICODE));
            return ['total' => count($rows), 'sent' => count($rows), 'failed' => 0];
        }

        self::markRowsFailed($rows, 'oa', json_encode($resp, JSON_UNESCAPED_UNICODE));
        return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
    }

    public static function processFeishuQueue($limit = 50, $eventHash = '', $timeout = 10)
    {
        $limit = min(50, max(1, intval($limit)));
        $rows = self::loadQueueRows('feishu', $limit, $eventHash);
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $mode = Settings::get('feishu_attendance_mode', 'flow');
        $feishu = new appLinkFeishu(true);
        $token = $feishu->getTenantAccessToken();
        if ($token === '') {
            self::markRowsFailed($rows, 'feishu', '无法获取 tenant_access_token');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }

        if ($mode !== 'custom') {
            return self::pushFeishuFlowBatch($rows, $token, $timeout);
        }

        $endpoint = Settings::get('feishu_attendance_endpoint', '');
        if ($endpoint === '') {
            self::markRowsFailed($rows, 'feishu', '飞书考勤推送端点未配置');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }

        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'open_id' => $row['employee_open_id'],
                'user_id' => $row['employee_user_id'],
                'employee_no' => $row['employee_no'],
                'name' => $row['employee_name'],
                'punch_time' => $row['punch_time_text'],
                'location' => self::externalAttendanceLocation($row),
                'source' => $row['source'],
                'event_hash' => $row['event_hash']
            ];
        }

        $resp = self::postJson($endpoint, ['records' => $records], ['Authorization: Bearer ' . $token], $timeout);
        $code = $resp['response']['code'] ?? null;
        $success = ($resp['status_code'] ?? 0) >= 200 && ($resp['status_code'] ?? 0) < 300 && ($code === null || intval($code) === 0 || intval($code) === 200);
        if ($success) {
            self::markRowsSent($rows, 'feishu', json_encode($resp['response'], JSON_UNESCAPED_UNICODE));
            return ['total' => count($rows), 'sent' => count($rows), 'failed' => 0];
        }

        self::markRowsFailed($rows, 'feishu', json_encode($resp, JSON_UNESCAPED_UNICODE));
        return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
    }

    public static function processMessageQueue($limit = 50, $eventHash = '')
    {
        $rows = self::loadQueueRows('message', $limit, $eventHash);
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $feishu = new appLinkFeishu(true);
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $resp = $feishu->sendInteractiveMessage($row['employee_open_id'], self::buildSwipeMessageCard($row), $row['event_hash']);
            if ($resp['ok']) {
                self::markRowsSent([$row], 'message', json_encode($resp['data'], JSON_UNESCAPED_UNICODE));
                $sent++;
            } else {
                self::markRowsFailed([$row], 'message', $resp['message']);
                $failed++;
            }
            usleep(50000);
        }

        return ['total' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    private static function buildSwipeMessageCard($row)
    {
        $titleText = Settings::get('feishu_message_template', '刷卡成功');
        if ($titleText === '') {
            $titleText = '刷卡成功';
        }
        $template = Settings::get('feishu_message_card_template', '');
        if (trim($template) === '') {
            $template = "**刷卡方式** 门禁刷卡\n**刷卡设备** {device}\n**刷卡时间** {datetime}";
        }
        $rendered = self::renderSwipeMessageTemplate($template, $row);
        $customCard = json_decode($rendered, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($customCard)) {
            $customCard['config'] = $customCard['config'] ?? ['wide_screen_mode' => true];
            $customCard['header'] = [
                'template' => $customCard['header']['template'] ?? 'green',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => self::renderSwipeMessageTemplate($titleText, $row)
                ]
            ];
            return $customCard;
        }

        return [
            'config' => [
                'wide_screen_mode' => true
            ],
            'header' => [
                'template' => 'green',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => self::renderSwipeMessageTemplate($titleText, $row)
                ]
            ],
            'elements' => [
                [
                    'tag' => 'markdown',
                    'content' => $rendered
                ]
            ]
        ];
    }

    private static function renderSwipeMessageTemplate($template, $row)
    {
        $eventTime = intval($row['punch_time'] ?? time());
        $deviceName = $row['door_name'] ?: ($row['location'] ?: '门禁设备');
        $cardId = (string)($row['card_id'] ?? '');
        $replacements = [
            '{time}' => date('H:i', $eventTime),
            '{date}' => date('Y年m月d日', $eventTime),
            '{datetime}' => date('Y-m-d H:i:s', $eventTime),
            '{name}' => (string)($row['employee_name'] ?? ''),
            '{device}' => $deviceName,
            '{location}' => self::externalAttendanceLocation($row),
            '{card_id}' => $cardId,
            '{card_mask}' => self::maskCardId($cardId),
            '{event_hash}' => (string)($row['event_hash'] ?? '')
        ];
        return strtr((string)$template, $replacements);
    }

    private static function maskCardId($cardId)
    {
        $cardId = trim((string)$cardId);
        $length = strlen($cardId);
        if ($length <= 4) {
            return $cardId;
        }
        return str_repeat('*', $length - 4) . substr($cardId, -4);
    }

    public static function remoteOpen($deviceId, $adminName)
    {
        $device = Database::querySingleLine('devices', ['id' => $deviceId]);
        if (!$device) {
            return ['ok' => false, 'message' => '设备不存在'];
        }
        if (!Settings::getBool('remote_open_enabled')) {
            return ['ok' => false, 'message' => '远程开门未启用'];
        }
        if (empty($device['ip'])) {
            return ['ok' => false, 'message' => '设备IP为空'];
        }

        $method = strtoupper(Settings::get('remote_open_method', 'GET'));
        if (!in_array($method, ['GET', 'POST'], true)) {
            $method = 'GET';
        }
        $path = self::renderRemoteOpenTemplate(Settings::get('remote_open_path', '/cdor.cgi?open=0'), $device);
        $url = self::remoteOpenUrl($device['ip'], $path);
        $body = self::renderRemoteOpenTemplate(Settings::get('remote_open_body', ''), $device);
        $timeout = Settings::getInt('remote_open_timeout', 3);
        $timeout = min(30, max(1, $timeout));
        $username = Settings::get('remote_open_username', 'admin');
        $password = Settings::get('remote_open_password', '888888');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($username !== '' || $password !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, defined('CURLAUTH_ANY') ? CURLAUTH_ANY : CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }
        if ($method === 'POST') {
            $headers = [];
            if ($body !== '') {
                $firstChar = substr(ltrim($body), 0, 1);
                $headers[] = in_array($firstChar, ['{', '['], true) ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
            }
            if (count($headers) > 0) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '') {
            self::writeAccessLog($adminName, '管理员', $device['name'], '', '管理员远程开门失败：'.$error, time());
            return ['ok' => false, 'message' => $error];
        }
        if ($httpCode >= 200 && $httpCode < 400 && self::remoteOpenResponseOk($responseBody)) {
            self::writeAccessLog($adminName, '管理员', $device['name'], '', '管理员远程开门成功', time());
            return ['ok' => true, 'message' => '远程开门指令已发送（HTTP '.$httpCode.'）', 'response' => $responseBody];
        }
        $message = '设备返回HTTP ' . $httpCode;
        if ($httpCode >= 200 && $httpCode < 400) {
            $message = '设备返回内容未匹配成功规则';
        }
        $responseText = self::remoteOpenResponseSummary($responseBody);
        if ($responseText !== '') {
            $message .= '：' . $responseText;
        }
        self::writeAccessLog($adminName, '管理员', $device['name'], '', '管理员远程开门失败：'.$message, time());
        return ['ok' => false, 'message' => $message, 'response' => $responseBody];
    }

    private static function renderRemoteOpenTemplate($value, $device)
    {
        global $_config;

        $openTime = $_config['doorOpenTime'] ?? 5;
        $replacements = [
            '{ip}' => $device['ip'] ?? '',
            '{device_id}' => $device['id'] ?? '',
            '{id}' => $device['id'] ?? '',
            '{device_name}' => $device['name'] ?? '',
            '{name}' => $device['name'] ?? '',
            '{did}' => $device['did'] ?? '',
            '{serial}' => $device['did'] ?? ($device['device_sn'] ?? ''),
            '{device_sn}' => $device['device_sn'] ?? '',
            '{mac}' => $device['mac'] ?? '',
            '{oemcode}' => $device['oemcode'] ?? '',
            '{open_time}' => $openTime,
            '{door_open_time}' => $openTime,
            '{timestamp}' => time()
        ];
        return strtr((string)$value, $replacements);
    }

    private static function remoteOpenUrl($deviceIp, $path)
    {
        $path = trim((string)$path);
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }
        $base = trim((string)$deviceIp);
        if (!preg_match('/^https?:\/\//i', $base)) {
            $base = 'http://' . $base;
        }
        return rtrim($base, '/') . (strpos($path, '/') === 0 ? $path : '/' . $path);
    }

    private static function remoteOpenResponseOk($responseBody)
    {
        $body = trim((string)$responseBody);
        $successText = trim(Settings::get('remote_open_success_text', ''));
        if ($successText !== '') {
            return strpos($body, $successText) !== false;
        }
        if ($body === '') {
            return true;
        }

        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            if (isset($json['AcsRes'])) {
                return (string)$json['AcsRes'] === '1';
            }
            if (isset($json['success'])) {
                return $json['success'] === true || $json['success'] === 1 || $json['success'] === '1' || $json['success'] === 'true';
            }
            if (isset($json['ok'])) {
                return $json['ok'] === true || $json['ok'] === 1 || $json['ok'] === '1' || $json['ok'] === 'true';
            }
            if (isset($json['code'])) {
                return intval($json['code']) === 0 || intval($json['code']) === 200;
            }
        }

        foreach (['invalid', 'error', 'fail', 'denied', 'unauthorized', 'forbidden', '失败', '错误', '无效', '拒绝'] as $keyword) {
            if (stripos($body, $keyword) !== false) {
                return false;
            }
        }
        return true;
    }

    private static function remoteOpenResponseSummary($responseBody)
    {
        $body = trim((string)$responseBody);
        if ($body === '') {
            return '';
        }
        $body = preg_replace('/\s+/', ' ', $body);
        if (function_exists('mb_substr')) {
            return mb_substr($body, 0, 180, 'UTF-8');
        }
        return substr($body, 0, 180);
    }

    private static function pushFeishuFlowBatch($rows, $tenantToken, $timeout)
    {
        $employeeType = Settings::get('feishu_employee_id_type', 'employee_no');
        if (!in_array($employeeType, ['employee_id', 'employee_no'], true)) {
            $employeeType = 'employee_no';
        }

        $validRows = [];
        $flowRecords = [];
        $failed = 0;
        foreach ($rows as $row) {
            $userId = $employeeType === 'employee_id' ? ($row['employee_user_id'] ?? '') : ($row['employee_no'] ?? '');
            if ($userId === '') {
                self::markRowsFailed([$row], 'feishu', '缺少飞书考勤用户ID');
                $failed++;
                continue;
            }

            $eventTime = intval($row['punch_time']);
            $eventHash = $row['event_hash'] ?? hash('sha256', implode('|', [$row['employee_open_id'] ?? '', $row['door_id'] ?? '', $row['card_id'] ?? '', $eventTime]));
            $validRows[] = $row;
            $flowRecords[] = [
                'user_id' => $userId,
                'creator_id' => $userId,
                'location_name' => self::externalAttendanceLocation($row),
                'check_time' => (string)$eventTime,
                'comment' => self::attendanceFlowComment(),
                'type' => 7,
                'external_id' => $eventHash,
                'idempotent_id' => $eventHash
            ];
        }

        if (count($flowRecords) === 0) {
            return ['total' => count($rows), 'sent' => 0, 'failed' => $failed];
        }

        $feishu = new appLinkFeishu(true);
        $endpoint = $feishu->endpoint('batchCreateAttendanceFlow');
        if ($endpoint === '') {
            self::markRowsFailed($validRows, 'feishu', '飞书 batchCreateAttendanceFlow endpoint 未在 config.php 中配置');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }
        $url = $endpoint . '?employee_type=' . rawurlencode($employeeType);
        $resp = self::postJson($url, ['flow_records' => $flowRecords], ['Authorization: Bearer ' . $tenantToken], $timeout);
        $code = intval($resp['response']['code'] ?? -1);
        if (($resp['status_code'] ?? 0) >= 200 && ($resp['status_code'] ?? 0) < 300 && $code === 0) {
            self::markRowsSent($validRows, 'feishu', json_encode($resp['response'], JSON_UNESCAPED_UNICODE));
            return ['total' => count($rows), 'sent' => count($validRows), 'failed' => $failed];
        }

        self::markRowsFailed($validRows, 'feishu', json_encode($resp, JSON_UNESCAPED_UNICODE));
        return ['total' => count($rows), 'sent' => 0, 'failed' => $failed + count($validRows)];
    }

    private static function externalAttendanceLocation($row)
    {
        $location = trim((string)($row['location'] ?? ''));
        if ($location === '') {
            $location = trim((string)($row['door_name'] ?? ''));
        }
        if ($location === '') {
            $location = '公司门禁';
        }
        return strpos($location, '工牌-') === 0 ? $location : '工牌-' . $location;
    }

    private static function attendanceFlowComment()
    {
        $comment = trim(Settings::get('feishu_attendance_flow_comment', '门禁刷卡自动同步'));
        return $comment === '' ? '门禁刷卡自动同步' : $comment;
    }

    private static function getOaToken($baseUrl, $appId, $appSecret)
    {
        $now = time();
        $cacheKey = hash('sha256', $baseUrl . '|' . $appId);
        $cache = self::loadRuntimeKeyFile();
        if (!empty($cache['oa_access_token']) && !empty($cache['oa_access_token_expires_at']) && ($cache['oa_access_token_cache_key'] ?? '') === $cacheKey) {
            if (intval($cache['oa_access_token_expires_at']) > $now + 300) {
                return $cache['oa_access_token'];
            }
        }

        $url = $baseUrl . Settings::get('oa_auth_path', '/open/auth/token') . '?appId=' . rawurlencode($appId) . '&appSecret=' . rawurlencode($appSecret);
        $resp = self::postJson($url, null, [], 10);
        if (($resp['status_code'] ?? 0) == 200 && intval($resp['response']['code'] ?? 0) === 200 && !empty($resp['response']['data']['accessToken'])) {
            $expiresIn = intval($resp['response']['data']['expiresIn'] ?? 7200);
            $cache['oa_access_token'] = $resp['response']['data']['accessToken'];
            $cache['oa_access_token_expires_at'] = $now + max(300, $expiresIn);
            $cache['oa_access_token_cache_key'] = $cacheKey;
            self::saveRuntimeKeyFile($cache);
            return $cache['oa_access_token'];
        }
        return '';
    }

    private static function loadRuntimeKeyFile()
    {
        $path = self::runtimeKeyFilePath();
        if ($path === '' || !file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private static function saveRuntimeKeyFile($data)
    {
        $path = self::runtimeKeyFilePath();
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return;
        }
        $fp = fopen($path, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $current = stream_get_contents($fp);
            $currentData = json_decode($current ?: '{}', true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($currentData)) {
                $data = array_merge($currentData, $data);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    private static function runtimeKeyFilePath()
    {
        global $_config;
        return $_config['feishu']['keyConfigFile'] ?? (defined('ROOT') ? ROOT . '/feishu_key.json' : '');
    }

    private static function loadQueueRows($target, $limit, $eventHash = '')
    {
        global $conn;

        $limit = max(1, intval($limit));
        $now = time();
        $target = mysqli_real_escape_string($conn, $target);
        $where = "`need_{$target}`=1 AND `{$target}_status`<>'sent' AND `{$target}_next_retry` <= {$now}";
        if ($eventHash !== '') {
            $eventHash = mysqli_real_escape_string($conn, $eventHash);
            $where .= " AND `event_hash`='{$eventHash}'";
        }
        $sql = "SELECT * FROM `attendance_queue` WHERE {$where} ORDER BY `id` ASC LIMIT {$limit}";
        $rs = Database::query('attendance_queue', $sql, '', true);
        $rows = [];
        if ($rs) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function hasDueQueueRows($target)
    {
        global $conn;

        $now = time();
        $target = mysqli_real_escape_string($conn, $target);
        $sql = "SELECT `id` FROM `attendance_queue` WHERE `need_{$target}`=1 AND `{$target}_status`<>'sent' AND `{$target}_next_retry` <= {$now} LIMIT 1";
        $rs = Database::query('attendance_queue', $sql, '', true);
        if ($rs && mysqli_fetch_assoc($rs)) {
            return true;
        }
        return false;
    }

    private static function markRowsSent($rows, $target, $response)
    {
        self::markRows($rows, $target, 'sent', 0, $response);
    }

    private static function markRowsFailed($rows, $target, $response)
    {
        self::markRows($rows, $target, 'failed', null, $response);
    }

    private static function markRows($rows, $target, $status, $nextRetry, $response)
    {
        global $conn;

        if (count($rows) === 0) {
            return;
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = intval($row['id']);
        }
        $now = time();
        $response = mysqli_real_escape_string($conn, substr((string)$response, 0, 60000));
        $target = mysqli_real_escape_string($conn, $target);
        $status = mysqli_real_escape_string($conn, $status);

        if ($status === 'failed') {
            foreach ($rows as $row) {
                $attempts = intval($row[$target . '_attempts'] ?? 0) + 1;
                $retryAt = $now + self::retryDelay($attempts);
                $id = intval($row['id']);
                mysqli_query($conn, "UPDATE `attendance_queue` SET `{$target}_status`='{$status}', `{$target}_attempts`={$attempts}, `{$target}_next_retry`={$retryAt}, `{$target}_response`='{$response}', `updated_at`={$now} WHERE `id`={$id}");
            }
            return;
        }

        $idList = implode(',', $ids);
        mysqli_query($conn, "UPDATE `attendance_queue` SET `{$target}_status`='{$status}', `{$target}_next_retry`=0, `{$target}_response`='{$response}', `updated_at`={$now} WHERE `id` IN ({$idList})");
    }

    private static function retryDelay($attempts)
    {
        $base = max(1, Settings::getInt('queue_retry_base_seconds', 60));
        $max = max($base, Settings::getInt('queue_retry_max_seconds', 3600));
        $delay = $base * pow(2, min(6, max(0, $attempts - 1)));
        return min($max, intval($delay));
    }

    private static function postJson($url, $body = null, $headers = [], $timeout = 10)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        $allHeaders = array_merge(['Content-Type: application/json; charset=utf-8'], $headers);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'status_code' => $httpCode,
            'response' => json_decode($raw, true),
            'raw' => $raw,
            'error' => $error
        ];
    }

    private static function getPolicies($deviceId, $subjectKind)
    {
        global $conn;

        $deviceId = intval($deviceId);
        $subjectKind = mysqli_real_escape_string($conn, $subjectKind);
        $sql = "SELECT * FROM `access_policies` WHERE `device_id`={$deviceId} AND `subject_kind`='{$subjectKind}' AND `enabled`=1";
        $rs = Database::query('access_policies', $sql, '', true);
        $policies = [];
        if ($rs) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $policies[] = $row;
            }
        }
        return $policies;
    }

    private static function matchEmployeePolicy($policy, $employeeInfo)
    {
        $type = $policy['subject_type'] ?? '';
        $value = $policy['subject_value'] ?? '';
        $extra = $policy['subject_extra'] ?? '';

        if ($type === 'all') {
            return true;
        }
        if ($type === 'employee') {
            return $value !== '' && $value === ($employeeInfo['open_id'] ?? '');
        }
        if ($type === 'department') {
            return self::valueMatches($value, self::employeeDepartments($employeeInfo));
        }
        if ($type === 'group') {
            return self::valueMatches($value, self::listValues($employeeInfo['groups'] ?? ''));
        }
        if ($type === 'role') {
            return self::employeeMatchesRole($value, $employeeInfo);
        }
        if ($type === 'department_group') {
            return self::valueMatches($value, self::employeeDepartments($employeeInfo))
                && self::valueMatches($extra, self::listValues($employeeInfo['groups'] ?? ''));
        }
        return false;
    }

    private static function employeeMatchesRole($roleId, $employeeInfo)
    {
        $roleId = trim((string)$roleId);
        if ($roleId === '') {
            return false;
        }

        if (!preg_match('/^[0-9]+$/', $roleId)) {
            return self::valueMatches($roleId, self::listValues($employeeInfo['roles'] ?? ''));
        }

        $role = Database::querySingleLine('access_roles', ['id' => intval($roleId), 'enabled' => 1]);
        if (!$role) {
            return false;
        }
        if (intval($role['allow_all'] ?? 0) === 1) {
            return true;
        }

        $openId = $employeeInfo['open_id'] ?? '';
        if ($openId === '') {
            return false;
        }

        $member = Database::querySingleLine('access_role_members', [
            'role_id' => intval($roleId),
            'employee_open_id' => $openId
        ]);
        return (bool)$member;
    }

    private static function employeeDepartments($employeeInfo)
    {
        $values = [];
        foreach (['department_id', 'department_name'] as $field) {
            if (!empty($employeeInfo[$field])) {
                $values[] = $employeeInfo[$field];
            }
        }
        return array_unique(array_merge($values, self::listValues($employeeInfo['department_ids'] ?? '')));
    }

    private static function listValues($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private static function valueMatches($needle, $haystack)
    {
        if ($needle === '') {
            return false;
        }
        foreach ($haystack as $item) {
            if ((string)$item === (string)$needle) {
                return true;
            }
        }
        return false;
    }

    private static function matchLegacyList($json, $openId)
    {
        if ($json === '' || $openId === '') {
            return false;
        }
        $list = json_decode($json, true);
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $item) {
            if (isset($item['value']) && $item['value'] === $openId) {
                return true;
            }
        }
        return false;
    }

    private static function policyLabel($policy)
    {
        $typeMap = [
            'all' => '全员',
            'employee' => '员工名单',
            'department' => '部门',
            'group' => '组',
            'role' => '角色',
            'department_group' => '部门+组'
        ];
        return $typeMap[$policy['subject_type'] ?? ''] ?? '策略';
    }
}
