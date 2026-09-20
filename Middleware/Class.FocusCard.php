<?php
/*

重点关注卡片飞书加急提醒
Ver 1.0.0.0 20260920
Code by Jason / Codex

*/

namespace anim210System;

class FocusCardAlertService {

    public static function enqueueSwipe($cardId, $deviceInfo, $eventTime = null, $subjectKind = '', $subjectInfo = [], $eventIdentity = '')
    {
        global $conn;

        if (!Settings::getBool('focus_card_alert_enabled') || !$conn) {
            return '';
        }

        $cardId = AttendanceService::normalizeCardNumber($cardId);
        if ($cardId === '' || !in_array($cardId, self::configuredCards(), true)) {
            return '';
        }

        $recipientIds = self::configuredList('focus_card_recipient_open_ids');
        if (count($recipientIds) === 0) {
            return '';
        }

        $urgentApp = Settings::getBool('focus_card_urgent_app_enabled', true);
        $urgentSms = Settings::getBool('focus_card_urgent_sms_enabled');
        $urgentPhone = Settings::getBool('focus_card_urgent_phone_enabled');
        if (!$urgentApp && !$urgentSms && !$urgentPhone) {
            return '';
        }

        $recipients = self::loadRecipients($recipientIds);
        if (count($recipients) === 0) {
            return '';
        }

        $eventTime = intval($eventTime ?: time());
        $doorId = intval($deviceInfo['id'] ?? 0);
        $identity = trim((string)$eventIdentity);
        if ($identity === '') {
            $identity = (string)$eventTime;
        }
        $eventHash = hash('sha256', implode('|', ['focus-card', $cardId, $doorId, $eventTime, $identity]));
        $subjectKind = in_array($subjectKind, ['employee', 'learner', 'guest'], true) ? $subjectKind : 'unknown';
        $subjectName = trim((string)($subjectInfo['name'] ?? ''));
        if ($subjectName === '' && $subjectKind === 'learner') {
            $subjectName = trim((string)($subjectInfo['realname'] ?? ''));
        }

        $now = time();
        $rows = [];
        foreach ($recipients as $recipient) {
            $rows[] = [
                'event_hash' => $eventHash,
                'recipient_open_id' => $recipient['open_id'],
                'recipient_name' => $recipient['name'],
                'card_id' => $cardId,
                'subject_kind' => $subjectKind,
                'subject_name' => $subjectName,
                'door_id' => $doorId,
                'door_name' => trim((string)($deviceInfo['name'] ?? '')),
                'swipe_time' => $eventTime,
                'message_id' => '',
                'urgent_app_status' => $urgentApp ? 'pending' : 'skipped',
                'urgent_sms_status' => $urgentSms ? 'pending' : 'skipped',
                'urgent_phone_status' => $urgentPhone ? 'pending' : 'skipped',
                'status' => 'pending',
                'attempts' => 0,
                'next_retry' => 0,
                'locked_at' => 0,
                'response' => '',
                'sent_at' => 0,
                'created_at' => $now,
                'updated_at' => $now
            ];
        }

        if (!self::insertRows($rows)) {
            return '';
        }

        self::scheduleImmediate($eventHash, count($rows));
        return $eventHash;
    }

    public static function processQueue($limit = 50, $eventHash = '')
    {
        $limit = max(1, min(200, intval($limit)));
        $rows = self::claimRows($limit, $eventHash);
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $feishu = new appLinkFeishu(true);
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if (self::processRow($row, $feishu)) {
                $sent++;
            } else {
                $failed++;
            }
            usleep(50000);
        }

        return ['total' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    private static function processRow($row, $feishu)
    {
        $responses = json_decode((string)($row['response'] ?? ''), true);
        if (!is_array($responses)) {
            $responses = [];
        }
        unset($responses['error']);
        $messageId = trim((string)($row['message_id'] ?? ''));
        if ($messageId === '') {
            $uuid = substr(hash('sha256', $row['event_hash'] . '|' . $row['recipient_open_id']), 0, 50);
            $message = $feishu->sendInteractiveMessage($row['recipient_open_id'], self::buildCard($row), $uuid);
            if (empty($message['ok'])) {
                self::markFailed($row, '发送红色卡片失败：' . ($message['message'] ?? '未知错误'));
                return false;
            }
            $messageId = trim((string)($message['data']['message_id'] ?? ''));
            if ($messageId === '') {
                self::markFailed($row, '飞书发送消息成功，但响应中缺少 message_id，无法继续加急');
                return false;
            }
            $responses['message'] = $message['data'] ?? [];
            if (!self::saveMessageId($row['id'], $messageId, $responses)) {
                self::markFailed($row, '红色卡片已发送，但保存 message_id 失败，将用相同 uuid 重试');
                return false;
            }
        }

        $channels = [
            'urgent_app_status' => ['应用内', 'sendUrgentAppMessage'],
            'urgent_sms_status' => ['短信', 'sendUrgentSmsMessage'],
            'urgent_phone_status' => ['电话', 'sendUrgentPhoneMessage']
        ];
        foreach ($channels as $field => $channel) {
            if (($row[$field] ?? 'skipped') === 'skipped' || ($row[$field] ?? '') === 'sent') {
                continue;
            }
            $method = $channel[1];
            $urgent = $feishu->{$method}($messageId, [$row['recipient_open_id']]);
            if (empty($urgent['ok'])) {
                self::markFailed($row, $channel[0] . '加急失败：' . ($urgent['message'] ?? '未知错误'), $responses);
                return false;
            }
            $row[$field] = 'sent';
            $responses[$field] = $urgent['data'] ?? [];
            if (!self::markChannelSent($row['id'], $field, $responses)) {
                self::markFailed($row, $channel[0] . '加急成功，但保存队列进度失败', $responses);
                return false;
            }
        }

        if (!self::markSent($row['id'], $responses)) {
            self::markFailed($row, '全部加急请求已成功，但保存队列完成状态失败', $responses);
            return false;
        }
        return true;
    }

    private static function buildCard($row)
    {
        $time = intval($row['swipe_time'] ?? time());
        $subjectKind = self::subjectKindLabel($row['subject_kind'] ?? '');
        $subjectName = trim((string)($row['subject_name'] ?? ''));
        $subjectText = $subjectName !== '' ? $subjectKind . ' · ' . $subjectName : '未绑定人员';
        $doorName = trim((string)($row['door_name'] ?? ''));
        if ($doorName === '') {
            $doorName = '未知门禁';
        }

        return [
            'config' => ['wide_screen_mode' => true],
            'header' => [
                'template' => 'red',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => '重点关注卡片刷卡提醒'
                ]
            ],
            'elements' => [[
                'tag' => 'markdown',
                'content' => "**重点卡号** " . ($row['card_id'] ?? '')
                    . "\n**持卡对象** " . $subjectText
                    . "\n**刷卡设备** " . $doorName
                    . "\n**刷卡时间** " . date('Y-m-d H:i:s', $time)
            ]]
        ];
    }

    private static function configuredCards()
    {
        $cards = [];
        foreach (self::configuredList('focus_card_numbers') as $cardId) {
            $cardId = AttendanceService::normalizeCardNumber($cardId);
            if (preg_match('/^\d{10}$/', $cardId) && !in_array($cardId, $cards, true)) {
                $cards[] = $cardId;
            }
        }
        return $cards;
    }

    private static function configuredList($key)
    {
        $value = Settings::get($key, '');
        $decoded = json_decode((string)$value, true);
        $items = is_array($decoded) ? $decoded : preg_split('/[,\n;\s]+/', (string)$value);
        $result = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    private static function loadRecipients($openIds)
    {
        global $conn;

        $escaped = [];
        foreach (array_slice($openIds, 0, 200) as $openId) {
            $escaped[] = "'" . mysqli_real_escape_string($conn, $openId) . "'";
        }
        if (count($escaped) === 0) {
            return [];
        }

        $rs = mysqli_query($conn, "SELECT `open_id`, `name`, `realname` FROM `employee` WHERE `open_id` IN (" . implode(',', $escaped) . ")");
        $byId = [];
        if ($rs) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    $name = trim((string)($row['realname'] ?? ''));
                }
                $byId[$row['open_id']] = ['open_id' => $row['open_id'], 'name' => $name];
            }
            mysqli_free_result($rs);
        }

        $result = [];
        foreach ($openIds as $openId) {
            if (isset($byId[$openId])) {
                $result[] = $byId[$openId];
            }
        }
        return $result;
    }

    private static function insertRows($rows)
    {
        global $conn;

        if (count($rows) === 0) {
            return false;
        }
        $columns = array_keys($rows[0]);
        $valueGroups = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = "'" . mysqli_real_escape_string($conn, (string)($row[$column] ?? '')) . "'";
            }
            $valueGroups[] = '(' . implode(',', $values) . ')';
        }
        $quotedColumns = array_map(function ($column) {
            return '`' . $column . '`';
        }, $columns);
        $sql = "INSERT IGNORE INTO `focus_card_message_queue` (" . implode(',', $quotedColumns) . ") VALUES " . implode(',', $valueGroups);
        return mysqli_query($conn, $sql) !== false;
    }

    private static function scheduleImmediate($eventHash, $recipientCount)
    {
        register_shutdown_function(function () use ($eventHash, $recipientCount) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            FocusCardAlertService::processQueue(max(1, min(200, intval($recipientCount))), $eventHash);
        });
    }

    private static function claimRows($limit, $eventHash)
    {
        global $conn;

        if (!$conn) {
            return [];
        }
        $now = time();
        $stale = $now - 120;
        $where = "((`status` IN ('pending','failed') AND `next_retry`<={$now}) OR (`status`='processing' AND `locked_at`<={$stale}))";
        if ($eventHash !== '') {
            $where .= " AND `event_hash`='" . mysqli_real_escape_string($conn, $eventHash) . "'";
        }
        $rs = mysqli_query($conn, "SELECT `id` FROM `focus_card_message_queue` WHERE {$where} ORDER BY `id` ASC LIMIT {$limit}");
        $ids = [];
        if ($rs) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $ids[] = intval($row['id']);
            }
            mysqli_free_result($rs);
        }

        $rows = [];
        foreach ($ids as $id) {
            $claimed = mysqli_query($conn, "UPDATE `focus_card_message_queue` SET `status`='processing', `locked_at`={$now}, `updated_at`={$now} WHERE `id`={$id} AND ((`status` IN ('pending','failed') AND `next_retry`<={$now}) OR (`status`='processing' AND `locked_at`<={$stale}))");
            if (!$claimed || mysqli_affected_rows($conn) !== 1) {
                continue;
            }
            $rowRs = mysqli_query($conn, "SELECT * FROM `focus_card_message_queue` WHERE `id`={$id} LIMIT 1");
            if ($rowRs && ($row = mysqli_fetch_assoc($rowRs))) {
                $rows[] = $row;
            }
            if ($rowRs) {
                mysqli_free_result($rowRs);
            }
        }
        return $rows;
    }

    private static function saveMessageId($id, $messageId, $responses)
    {
        global $conn;
        $id = intval($id);
        $messageId = mysqli_real_escape_string($conn, $messageId);
        $response = self::escapeResponse($responses);
        return mysqli_query($conn, "UPDATE `focus_card_message_queue` SET `message_id`='{$messageId}', `response`='{$response}', `updated_at`=" . time() . " WHERE `id`={$id}") !== false;
    }

    private static function markChannelSent($id, $field, $responses)
    {
        global $conn;
        if (!in_array($field, ['urgent_app_status', 'urgent_sms_status', 'urgent_phone_status'], true)) {
            return false;
        }
        $id = intval($id);
        $response = self::escapeResponse($responses);
        return mysqli_query($conn, "UPDATE `focus_card_message_queue` SET `{$field}`='sent', `response`='{$response}', `updated_at`=" . time() . " WHERE `id`={$id}") !== false;
    }

    private static function markSent($id, $responses)
    {
        global $conn;
        $id = intval($id);
        $now = time();
        $response = self::escapeResponse($responses);
        return mysqli_query($conn, "UPDATE `focus_card_message_queue` SET `status`='sent', `next_retry`=0, `locked_at`=0, `response`='{$response}', `sent_at`={$now}, `updated_at`={$now} WHERE `id`={$id}") !== false;
    }

    private static function markFailed($row, $message, $responses = [])
    {
        global $conn;
        $id = intval($row['id'] ?? 0);
        $attempts = intval($row['attempts'] ?? 0) + 1;
        $now = time();
        $nextRetry = $now + self::retryDelay($attempts);
        $responses['error'] = $message;
        $response = self::escapeResponse($responses);
        mysqli_query($conn, "UPDATE `focus_card_message_queue` SET `status`='failed', `attempts`={$attempts}, `next_retry`={$nextRetry}, `locked_at`=0, `response`='{$response}', `updated_at`={$now} WHERE `id`={$id}");
    }

    private static function retryDelay($attempts)
    {
        $base = max(1, Settings::getInt('queue_retry_base_seconds', 60));
        $max = max($base, Settings::getInt('queue_retry_max_seconds', 3600));
        return min($max, intval($base * pow(2, min(6, max(0, $attempts - 1)))));
    }

    private static function escapeResponse($responses)
    {
        global $conn;
        $json = json_encode($responses, JSON_UNESCAPED_UNICODE);
        return mysqli_real_escape_string($conn, substr((string)$json, 0, 60000));
    }

    private static function subjectKindLabel($kind)
    {
        $labels = ['employee' => '员工', 'learner' => '学员', 'guest' => '访客'];
        return $labels[$kind] ?? '未知人员';
    }
}
