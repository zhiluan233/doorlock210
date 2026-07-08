<?php
/*

系统设置读取与持久化模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

class Settings {

    private static $cache = null;

    public static function defaults()
    {
        return [
            'schema_version' => '0',

            'oa_attendance_enabled' => 'false',
            'oa_base_url' => '',
            'oa_auth_path' => '/open/auth/token',
            'oa_upload_path' => '/open/user/v1/badgeAttendance/upload',
            'oa_location_default' => '公司门禁',
            'oa_batch_size' => '100',

            'feishu_attendance_enabled' => 'false',
            'card_as_attendance_enabled' => 'true',
            'feishu_attendance_mode' => 'flow',
            'feishu_employee_id_type' => 'employee_no',
            'feishu_attendance_batch_size' => '50',
            'feishu_message_enabled' => 'false',
            'feishu_message_template' => '刷卡成功',
            'feishu_message_card_template' => "**刷卡方式** 门禁刷卡\n**刷卡设备** {device}\n**刷卡时间** {datetime}",
            'feishu_message_batch_size' => '50',

            'feishu_event_enabled' => 'true',
            'feishu_contact_sync_enabled' => 'true',
            'feishu_contact_sync_daily_time' => '03:25',
            'feishu_contact_sync_release_missing' => 'true',
            'feishu_contact_sync_last_date' => '',
            'feishu_contact_sync_last_full_at' => '0',
            'feishu_contact_incremental_last_at' => '0',
            'feishu_contact_incremental_last_event' => '',

            'feishu_oauth_enabled' => 'true',
            'feishu_oauth_redirect_uri' => '',
            'feishu_oauth_scope' => '',
            'feishu_oauth_prompt' => '',

            'remote_open_enabled' => 'true',
            'remote_open_path' => '/cdor.cgi?open=0',
            'remote_open_timeout' => '3',

            'queue_retry_base_seconds' => '60',
            'queue_retry_max_seconds' => '3600'
        ];
    }

    public static function all()
    {
        self::load();
        return self::$cache;
    }

    public static function get($key, $default = null)
    {
        self::load();
        if (self::isConfigManagedKey($key)) {
            return self::configFallback($key) ?? $default;
        }
        if (self::isCredentialKey($key)) {
            return self::configFallback($key) ?? $default;
        }

        if (isset(self::$cache[$key]) && self::$cache[$key] !== '') {
            return self::$cache[$key];
        }

        $fallback = self::configFallback($key);
        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        return $default;
    }

    public static function getBool($key, $default = false)
    {
        $value = self::get($key, $default ? 'true' : 'false');
        return $value === true || $value === 'true' || $value === '1' || $value === 1 || $value === 'on';
    }

    public static function getInt($key, $default = 0)
    {
        return intval(self::get($key, (string)$default));
    }

    public static function set($key, $value)
    {
        global $conn;

        if (!self::tableExists()) {
            return false;
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif ($value === null) {
            $value = '';
        }

        $key = mysqli_real_escape_string($conn, $key);
        $value = mysqli_real_escape_string($conn, (string)$value);
        $now = time();
        $sql = "INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('{$key}', '{$value}', {$now}) "
             . "ON DUPLICATE KEY UPDATE `setting_value`='{$value}', `updated_at`={$now}";
        $ok = mysqli_query($conn, $sql);
        self::$cache = null;
        return $ok ? true : mysqli_error($conn);
    }

    public static function setMany($values)
    {
        foreach ($values as $key => $value) {
            $result = self::set($key, $value);
            if ($result !== true) {
                return $result;
            }
        }
        return true;
    }

    private static function isCredentialKey($key)
    {
        return in_array($key, [
            'feishu_app_id',
            'feishu_app_secret',
            'oa_app_id',
            'oa_app_secret',
            'remote_open_username',
            'remote_open_password',
            'feishu_event_token',
            'feishu_event_encrypt_key'
        ], true);
    }

    private static function isConfigManagedKey($key)
    {
        return in_array($key, [
            'feishu_oauth_authorize_url',
            'feishu_attendance_endpoint',
            'feishu_attendance_flow_comment'
        ], true);
    }

    public static function invalidate()
    {
        self::$cache = null;
    }

    private static function load()
    {
        global $conn;

        if (self::$cache !== null) {
            return;
        }

        self::$cache = self::defaults();
        if (!$conn || !self::tableExists()) {
            return;
        }

        $rs = mysqli_query($conn, "SELECT `setting_key`, `setting_value` FROM `system_settings`");
        if (!$rs) {
            return;
        }
        while ($row = mysqli_fetch_assoc($rs)) {
            self::$cache[$row['setting_key']] = $row['setting_value'];
        }
    }

    private static function tableExists()
    {
        global $conn;

        if (!$conn) {
            return false;
        }
        $rs = mysqli_query($conn, "SHOW TABLES LIKE 'system_settings'");
        return $rs && mysqli_num_rows($rs) > 0;
    }

    private static function configFallback($key)
    {
        global $_config;

        if ($key === 'feishu_app_id' && isset($_config['feishu']['appId'])) {
            return $_config['feishu']['appId'];
        }
        if ($key === 'feishu_app_secret' && isset($_config['feishu']['appSecret'])) {
            return $_config['feishu']['appSecret'];
        }
        if ($key === 'oa_app_id' && isset($_config['oa']['appId'])) {
            return $_config['oa']['appId'];
        }
        if ($key === 'oa_app_secret' && isset($_config['oa']['appSecret'])) {
            return $_config['oa']['appSecret'];
        }
        if ($key === 'remote_open_username' && isset($_config['remoteOpen']['username'])) {
            return $_config['remoteOpen']['username'];
        }
        if ($key === 'remote_open_password' && isset($_config['remoteOpen']['password'])) {
            return $_config['remoteOpen']['password'];
        }
        if ($key === 'feishu_event_token' && isset($_config['feishu']['eventToken'])) {
            return $_config['feishu']['eventToken'];
        }
        if ($key === 'feishu_event_encrypt_key' && isset($_config['feishu']['eventEncryptKey'])) {
            return $_config['feishu']['eventEncryptKey'];
        }
        if ($key === 'feishu_oauth_authorize_url') {
            $url = $_config['feishu']['appEndpoint']['oauthAuthorize'] ?? '';
            if ($url === '' || strpos($url, 'open.feishu.cn/open-apis/authen/v1/index') !== false) {
                return 'https://accounts.feishu.cn/open-apis/authen/v1/authorize';
            }
            return $url;
        }
        if ($key === 'feishu_attendance_endpoint' && isset($_config['feishu']['appEndpoint']['attendanceCustom'])) {
            return $_config['feishu']['appEndpoint']['attendanceCustom'];
        }
        if ($key === 'feishu_attendance_flow_comment') {
            return $_config['feishu']['attendanceFlowComment'] ?? '门禁刷卡自动同步';
        }
        return null;
    }
}
