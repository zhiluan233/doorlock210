<?php
/*

飞书事件订阅处理模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

class FeishuEventHandler {

    public static function handle()
    {
        Header("Content-Type: application/json; charset=utf-8");

        if (!Settings::getBool('feishu_event_enabled')) {
            http_response_code(404);
            exit(json_encode(['code' => 404, 'msg' => 'event disabled'], JSON_UNESCAPED_UNICODE));
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            exit(json_encode(['code' => 400, 'msg' => 'invalid json'], JSON_UNESCAPED_UNICODE));
        }

        if (isset($payload['encrypt'])) {
            $payload = self::decryptPayload($payload['encrypt']);
            if (!is_array($payload)) {
                http_response_code(400);
                exit(json_encode(['code' => 400, 'msg' => 'decrypt failed'], JSON_UNESCAPED_UNICODE));
            }
        }

        if (!self::verifyToken($payload)) {
            http_response_code(403);
            exit(json_encode(['code' => 403, 'msg' => 'invalid token'], JSON_UNESCAPED_UNICODE));
        }

        $challenge = self::extractChallenge($payload);
        if ($challenge !== '') {
            exit(json_encode(['challenge' => $challenge], JSON_UNESCAPED_UNICODE));
        }

        $eventType = self::eventType($payload);
        $eventId = self::eventId($payload);
        if ($eventId !== '' && self::isDuplicate($eventId)) {
            exit(json_encode(['code' => 0, 'msg' => 'duplicate'], JSON_UNESCAPED_UNICODE));
        }

        $openId = self::applyContactEvent($payload, $eventType);
        self::storeEvent($eventId ?: hash('sha256', json_encode($payload)), $eventType, $openId, $payload);

        exit(json_encode(['code' => 0, 'msg' => 'success'], JSON_UNESCAPED_UNICODE));
    }

    private static function applyContactEvent($payload, $eventType)
    {
        $event = $payload['event'] ?? $payload;
        $user = $event['user'] ?? $event['object'] ?? $event;

        $openId = $user['open_id'] ?? $user['open_user_id'] ?? $event['open_id'] ?? '';
        if ($openId === '') {
            return '';
        }

        $inactiveByType = preg_match('/deleted|resign|resigned|leave|exit|deactivate|disable/i', $eventType);
        $active = !$inactiveByType;
        if (isset($user['status']) && is_array($user['status'])) {
            $status = $user['status'];
            $active = !(
                (isset($status['is_activated']) && $status['is_activated'] !== true) ||
                (isset($status['is_frozen']) && $status['is_frozen'] == true) ||
                (isset($status['is_resigned']) && $status['is_resigned'] == true) ||
                (isset($status['is_exited']) && $status['is_exited'] == true) ||
                (isset($status['is_unjoin']) && $status['is_unjoin'] == true)
            );
        }

        $exists = Database::querySingleLine('employee', ['open_id' => $openId]);
        $data = [
            'open_id' => $openId,
            'name' => $user['name'] ?? $event['name'] ?? ($exists['name'] ?? ''),
            'employee_id' => $user['employee_no'] ?? $user['employee_id'] ?? ($exists['employee_id'] ?? ''),
            'realname' => $user['realname'] ?? $user['real_name'] ?? ($exists['realname'] ?? '--'),
            'status' => $active ? 'true' : 'false',
            'department_id' => self::firstValue($user['department_ids'] ?? $user['department_id'] ?? ''),
            'department_ids' => json_encode(self::arrayValue($user['department_ids'] ?? []), JSON_UNESCAPED_UNICODE),
            'user_id' => $user['user_id'] ?? ($exists['user_id'] ?? ''),
            'union_id' => $user['union_id'] ?? ($exists['union_id'] ?? ''),
            'email' => $user['email'] ?? ($exists['email'] ?? ''),
            'mobile' => $user['mobile'] ?? ($exists['mobile'] ?? ''),
            'tenant_key' => $payload['header']['tenant_key'] ?? ($exists['tenant_key'] ?? ''),
            'updated_at' => time()
        ];

        if ($exists) {
            unset($data['open_id']);
            Database::update('employee', $data, ['open_id' => $openId]);
        } else {
            Database::insert('employee', $data);
        }

        return $openId;
    }

    private static function verifyToken($payload)
    {
        $token = Settings::get('feishu_event_token', '');
        if ($token === '') {
            return true;
        }
        $incoming = $payload['token'] ?? $payload['header']['token'] ?? '';
        return hash_equals($token, $incoming);
    }

    private static function extractChallenge($payload)
    {
        if (($payload['type'] ?? '') === 'url_verification' && !empty($payload['challenge'])) {
            return $payload['challenge'];
        }
        if (($payload['header']['event_type'] ?? '') === 'url_verification' && !empty($payload['event']['challenge'])) {
            return $payload['event']['challenge'];
        }
        return '';
    }

    private static function eventType($payload)
    {
        return $payload['header']['event_type'] ?? $payload['type'] ?? $payload['event']['type'] ?? '';
    }

    private static function eventId($payload)
    {
        return $payload['header']['event_id'] ?? $payload['uuid'] ?? '';
    }

    private static function isDuplicate($eventId)
    {
        if ($eventId === '') {
            return false;
        }
        $exists = Database::querySingleLine('feishu_event_log', ['event_id' => $eventId]);
        return $exists ? true : false;
    }

    private static function storeEvent($eventId, $eventType, $openId, $payload)
    {
        Database::insert('feishu_event_log', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'open_id' => $openId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => time()
        ]);
    }

    private static function decryptPayload($encrypt)
    {
        $encryptKey = Settings::get('feishu_event_encrypt_key', '');
        if ($encryptKey === '') {
            return null;
        }

        $key = hash('sha256', $encryptKey, true);
        $decoded = base64_decode($encrypt, true);
        if ($decoded === false) {
            return null;
        }

        $candidates = [
            [substr($decoded, 0, 16), substr($decoded, 16)],
            [substr($key, 0, 16), $decoded]
        ];
        foreach ($candidates as $candidate) {
            $plain = openssl_decrypt($candidate[1], 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $candidate[0]);
            if ($plain === false) {
                continue;
            }
            $payload = json_decode($plain, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
                return $payload;
            }
        }
        return null;
    }

    private static function firstValue($value)
    {
        if (is_array($value)) {
            return $value[0] ?? '';
        }
        return (string)$value;
    }

    private static function arrayValue($value)
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === '') {
            return [];
        }
        return [$value];
    }
}
