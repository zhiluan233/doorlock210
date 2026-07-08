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
        if ($needFeishu && Settings::getBool('swipe_async_feishu_enabled')) {
            self::scheduleAsyncFeishu($eventHash);
        }
        return $eventHash;
    }

    public static function scheduleAsyncFeishu($eventHash)
    {
        register_shutdown_function(function () use ($eventHash) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            AttendanceService::processFeishuQueue(1, $eventHash, 3);
        });
    }

    public static function processAllQueues()
    {
        $result = [
            'oa' => self::processOaQueue(Settings::getInt('oa_batch_size', 100)),
            'feishu' => self::processFeishuQueue(Settings::getInt('feishu_attendance_batch_size', 50)),
            'message' => self::processMessageQueue(Settings::getInt('feishu_message_batch_size', 50))
        ];
        return $result;
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
                'location' => $row['location'] ?: $row['door_name']
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
        $rows = self::loadQueueRows('feishu', $limit, $eventHash);
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $mode = Settings::get('feishu_attendance_mode', 'custom');
        $feishu = new appLinkFeishu(true);
        $token = $feishu->getTenantAccessToken();
        if ($token === '') {
            self::markRowsFailed($rows, 'feishu', '无法获取 tenant_access_token');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }

        $sent = 0;
        $failed = 0;
        if ($mode === 'remedy') {
            foreach ($rows as $row) {
                $ok = self::pushFeishuRemedy($row, $token, $timeout);
                $sent += $ok ? 1 : 0;
                $failed += $ok ? 0 : 1;
            }
            return ['total' => count($rows), 'sent' => $sent, 'failed' => $failed];
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
                'location' => $row['location'] ?: $row['door_name'],
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

    public static function processMessageQueue($limit = 50)
    {
        $rows = self::loadQueueRows('message', $limit);
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
        $eventTime = intval($row['punch_time']);
        $titleText = Settings::get('feishu_message_template', '刷卡成功');
        if ($titleText === '' || strpos($titleText, '打卡') !== false) {
            $titleText = '刷卡成功';
        }
        $deviceName = $row['door_name'] ?: ($row['location'] ?: '门禁设备');

        return [
            'config' => [
                'wide_screen_mode' => true
            ],
            'header' => [
                'template' => 'green',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => date('H:i', $eventTime) . ' ' . $titleText
                ]
            ],
            'elements' => [
                [
                    'tag' => 'markdown',
                    'content' => '**刷卡方式** 门禁刷卡' . "\n" . '**刷卡设备** ' . $deviceName
                ],
                [
                    'tag' => 'div',
                    'text' => [
                        'tag' => 'plain_text',
                        'content' => date('Y年m月d日', $eventTime)
                    ]
                ]
            ]
        ];
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

        $path = Settings::get('remote_open_path', '/cdor.cgi?open=0');
        $url = 'http://' . $device['ip'] . (strpos($path, '/') === 0 ? $path : '/' . $path);
        $timeout = Settings::getInt('remote_open_timeout', 3);
        $username = Settings::get('remote_open_username', 'admin');
        $password = Settings::get('remote_open_password', '888888');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        if ($username !== '' || $password !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }
        $body = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::writeAccessLog($adminName, '管理员', $device['name'], '', '管理员远程开门', time());
        if ($error !== '') {
            return ['ok' => false, 'message' => $error];
        }
        if ($httpCode >= 200 && $httpCode < 400) {
            return ['ok' => true, 'message' => '远程开门指令已发送', 'response' => $body];
        }
        return ['ok' => false, 'message' => '设备返回HTTP ' . $httpCode, 'response' => $body];
    }

    private static function pushFeishuRemedy($row, $tenantToken, $timeout)
    {
        $employeeType = Settings::get('feishu_employee_id_type', 'employee_no');
        $userId = $employeeType === 'employee_id' ? $row['employee_user_id'] : $row['employee_no'];
        if ($userId === '') {
            self::markRowsFailed([$row], 'feishu', '缺少飞书考勤用户ID');
            return false;
        }

        $eventTime = intval($row['punch_time']);
        $body = [
            'user_id' => $userId,
            'remedy_date' => intval(date('Ymd', $eventTime)),
            'punch_no' => 0,
            'work_type' => intval(date('G', $eventTime)) < 12 ? 1 : 2,
            'remedy_time' => date('Y-m-d H:i', $eventTime),
            'reason' => '门禁刷卡自动同步',
            'time' => '-'
        ];
        $feishu = new appLinkFeishu(true);
        $endpoint = $feishu->endpoint('createAttendanceRemedy');
        if ($endpoint === '') {
            self::markRowsFailed([$row], 'feishu', '飞书 createAttendanceRemedy endpoint 未在 config.php 中配置');
            return false;
        }
        $url = $endpoint . '?employee_type=' . rawurlencode($employeeType);
        $resp = self::postJson($url, $body, ['Authorization: Bearer ' . $tenantToken], $timeout);
        $code = intval($resp['response']['code'] ?? -1);
        if (($resp['status_code'] ?? 0) >= 200 && ($resp['status_code'] ?? 0) < 300 && ($code === 0 || $code === 1226501)) {
            self::markRowsSent([$row], 'feishu', json_encode($resp['response'], JSON_UNESCAPED_UNICODE));
            return true;
        }

        self::markRowsFailed([$row], 'feishu', json_encode($resp, JSON_UNESCAPED_UNICODE));
        return false;
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
            return self::valueMatches($value, self::listValues($employeeInfo['roles'] ?? ''));
        }
        if ($type === 'department_group') {
            return self::valueMatches($value, self::employeeDepartments($employeeInfo))
                && self::valueMatches($extra, self::listValues($employeeInfo['groups'] ?? ''));
        }
        return false;
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
