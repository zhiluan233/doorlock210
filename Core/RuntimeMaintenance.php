<?php
/*

运行期维护与过期数据清理模块
Ver 1.0.0.0 20260722
Code by Jason / Codex

*/
namespace anim210System;

class RuntimeMaintenance {

    public static function runScheduledCleanup($limit = 200)
    {
        $limit = max(1, min(1000, intval($limit)));
        return [
            'expired_guests' => self::deleteExpiredGuests($limit),
            'expired_roles' => self::disableExpiredRoles($limit)
        ];
    }

    public static function deleteExpiredGuests($limit = 200)
    {
        $now = time();
        $limit = max(1, min(1000, intval($limit)));
        $sql = "SELECT * FROM `guest` WHERE `expires_at`>0 AND `expires_at`<{$now} ORDER BY `expires_at` ASC LIMIT {$limit}";
        $rs = Database::query('guest', $sql, '', true);
        $deleted = 0;
        $failed = 0;
        $items = [];
        if ($rs instanceof \mysqli_result) {
            while ($guest = mysqli_fetch_assoc($rs)) {
                $result = self::deleteGuest($guest, 'expired');
                if ($result === true) {
                    $deleted++;
                    $items[] = [
                        'id' => intval($guest['id'] ?? 0),
                        'name' => (string)($guest['name'] ?? ''),
                        'expires_at' => intval($guest['expires_at'] ?? 0)
                    ];
                } else {
                    $failed++;
                }
            }
            mysqli_free_result($rs);
        }
        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'items' => $items
        ];
    }

    public static function disableExpiredRoles($limit = 200)
    {
        $now = time();
        $limit = max(1, min(1000, intval($limit)));
        $sql = "SELECT * FROM `access_roles` WHERE `enabled`=1 AND `expires_at`>0 AND `expires_at`<{$now} ORDER BY `expires_at` ASC LIMIT {$limit}";
        $rs = Database::query('access_roles', $sql, '', true);
        $disabled = 0;
        $failed = 0;
        $items = [];
        if ($rs instanceof \mysqli_result) {
            while ($role = mysqli_fetch_assoc($rs)) {
                if (($role['builtin_key'] ?? '') !== '') {
                    continue;
                }
                $result = Database::update('access_roles', [
                    'enabled' => 0,
                    'updated_at' => $now
                ], ['id' => $role['id']]);
                if ($result === true) {
                    $disabled++;
                    $items[] = [
                        'id' => intval($role['id'] ?? 0),
                        'name' => (string)($role['name'] ?? ''),
                        'expires_at' => intval($role['expires_at'] ?? 0)
                    ];
                    self::logSystemOperation(
                        'auto_disable_expired_role',
                        '自动停用过期角色',
                        '门禁角色 ' . ($role['name'] ?? '') . ' 已于 ' . date('Y-m-d', intval($role['expires_at'])) . ' 过期，系统自动停用',
                        'access_role',
                        (string)($role['id'] ?? ''),
                        (string)($role['name'] ?? '')
                    );
                } else {
                    $failed++;
                }
            }
            mysqli_free_result($rs);
        }
        return [
            'disabled' => $disabled,
            'failed' => $failed,
            'items' => $items
        ];
    }

    private static function deleteGuest($guest, $reason)
    {
        $guestId = intval($guest['id'] ?? 0);
        if ($guestId <= 0) {
            return false;
        }
        $openId = trim((string)($guest['open_id'] ?? ''));
        if ($openId !== '') {
            Database::delete('access_role_members', [
                'member_kind' => 'guest',
                'employee_open_id' => $openId
            ]);
            Database::delete('access_policies', [
                'subject_kind' => 'guest',
                'subject_type' => 'guest',
                'subject_value' => $openId
            ]);
            self::removeGuestFromLegacyDeviceLists($openId);
        }
        $delete = Database::delete('guest', ['id' => $guestId]);
        if ($delete !== true) {
            return $delete;
        }

        if ($reason === 'expired') {
            $name = (string)($guest['name'] ?? '');
            $expiresAt = intval($guest['expires_at'] ?? 0);
            $cardId = (string)($guest['card_id'] ?? '');
            AttendanceService::writeAccessLog($name, '访客', '系统', $cardId, '访客过期自动删除', time());
            self::logSystemOperation(
                'auto_delete_expired_guest',
                '自动删除过期访客',
                '访客 ' . $name . ' 已于 ' . date('Y-m-d', $expiresAt) . ' 过期，系统自动删除',
                'guest',
                (string)$guestId,
                $name
            );
        }
        return true;
    }

    private static function removeGuestFromLegacyDeviceLists($openId)
    {
        $rs = Database::query('devices', "SELECT `id`, `allowedGuest` FROM `devices` WHERE `allowedGuest`<>''", '', true);
        if (!($rs instanceof \mysqli_result)) {
            return;
        }
        while ($device = mysqli_fetch_assoc($rs)) {
            $list = json_decode($device['allowedGuest'] ?? '', true);
            if (!is_array($list)) {
                continue;
            }
            $newList = [];
            $changed = false;
            foreach ($list as $item) {
                if (is_array($item) && isset($item['value']) && (string)$item['value'] === $openId) {
                    $changed = true;
                    continue;
                }
                $newList[] = $item;
            }
            if ($changed) {
                Database::update('devices', [
                    'allowedGuest' => json_encode($newList, JSON_UNESCAPED_UNICODE)
                ], ['id' => $device['id']]);
            }
        }
        mysqli_free_result($rs);
    }

    private static function logSystemOperation($code, $name, $detail, $targetType, $targetId, $targetName)
    {
        if (!class_exists(__NAMESPACE__ . '\\OperationLog')) {
            return false;
        }
        return OperationLog::record($code, $name, $detail, [
            'module' => 'cron',
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_name' => $targetName
        ], [
            'id' => 0,
            'username' => 'system',
            'display_name' => '系统计划任务',
            'type' => 'system'
        ]);
    }
}
