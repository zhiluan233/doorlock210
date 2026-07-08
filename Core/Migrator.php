<?php

namespace anim210System;

class Migrator {

    const SCHEMA_VERSION = '20260708';

    public static function ensure()
    {
        global $conn, $_config;

        if (!$conn) {
            return ['ok' => false, 'message' => '数据库未连接'];
        }

        $errors = [];
        self::exec("CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` varchar(100) NOT NULL,
            `setting_value` text,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `access_policies` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `device_id` bigint unsigned NOT NULL,
            `subject_kind` varchar(20) NOT NULL DEFAULT 'employee',
            `subject_type` varchar(40) NOT NULL,
            `subject_value` varchar(255) NOT NULL DEFAULT '',
            `subject_extra` varchar(255) NOT NULL DEFAULT '',
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `note` varchar(255) NOT NULL DEFAULT '',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_policy` (`device_id`, `subject_kind`, `subject_type`, `subject_value`, `subject_extra`),
            KEY `idx_device_kind` (`device_id`, `subject_kind`, `enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_queue` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `event_hash` char(64) NOT NULL,
            `source` varchar(32) NOT NULL DEFAULT 'card',
            `employee_open_id` varchar(128) NOT NULL DEFAULT '',
            `employee_user_id` varchar(128) NOT NULL DEFAULT '',
            `employee_no` varchar(128) NOT NULL DEFAULT '',
            `employee_name` varchar(255) NOT NULL DEFAULT '',
            `door_id` bigint unsigned DEFAULT NULL,
            `door_name` varchar(255) NOT NULL DEFAULT '',
            `card_id` varchar(64) NOT NULL DEFAULT '',
            `punch_time` int unsigned NOT NULL,
            `punch_time_text` varchar(32) NOT NULL,
            `location` varchar(255) NOT NULL DEFAULT '',
            `need_oa` tinyint(1) NOT NULL DEFAULT 0,
            `need_feishu` tinyint(1) NOT NULL DEFAULT 0,
            `need_message` tinyint(1) NOT NULL DEFAULT 0,
            `oa_status` varchar(20) NOT NULL DEFAULT 'skipped',
            `oa_attempts` int unsigned NOT NULL DEFAULT 0,
            `oa_next_retry` int unsigned NOT NULL DEFAULT 0,
            `oa_response` text,
            `feishu_status` varchar(20) NOT NULL DEFAULT 'skipped',
            `feishu_attempts` int unsigned NOT NULL DEFAULT 0,
            `feishu_next_retry` int unsigned NOT NULL DEFAULT 0,
            `feishu_response` text,
            `message_status` varchar(20) NOT NULL DEFAULT 'skipped',
            `message_attempts` int unsigned NOT NULL DEFAULT 0,
            `message_next_retry` int unsigned NOT NULL DEFAULT 0,
            `message_response` text,
            `created_at` int unsigned NOT NULL,
            `updated_at` int unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_event_hash` (`event_hash`),
            KEY `idx_oa_retry` (`need_oa`, `oa_status`, `oa_next_retry`),
            KEY `idx_feishu_retry` (`need_feishu`, `feishu_status`, `feishu_next_retry`),
            KEY `idx_message_retry` (`need_message`, `message_status`, `message_next_retry`),
            KEY `idx_employee_time` (`employee_open_id`, `punch_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `feishu_event_log` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `event_id` varchar(128) NOT NULL,
            `event_type` varchar(128) NOT NULL DEFAULT '',
            `open_id` varchar(128) NOT NULL DEFAULT '',
            `payload` mediumtext,
            `created_at` int unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_event_id` (`event_id`),
            KEY `idx_open_id` (`open_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::addColumn('employee', 'card_id', "varchar(64) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'department_id', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'department_name', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'department_ids', "text", $errors);
        self::addColumn('employee', 'groups', "text", $errors);
        self::addColumn('employee', 'roles', "text", $errors);
        self::addColumn('employee', 'user_id', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'union_id', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'email', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'mobile', "varchar(64) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'tenant_key', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'updated_at', "int unsigned NOT NULL DEFAULT 0", $errors);

        self::addColumn('guest', 'card_id', "varchar(64) NOT NULL DEFAULT ''", $errors);

        self::addColumn('devices', 'allowedEmployee', "longtext", $errors);
        self::addColumn('devices', 'allowedGuest', "longtext", $errors);
        self::addColumn('devices', 'dtype', "varchar(32) NOT NULL DEFAULT 'card_http'", $errors);
        self::addColumn('devices', 'device_sn', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'status', "varchar(32) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'mqtt_host', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'mqtt_port', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('devices', 'mqtt_username', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'mqtt_password', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'mqtt_qos', "int unsigned NOT NULL DEFAULT 0", $errors);

        self::addColumn('user', 'open_id', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('user', 'employee_id', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('user', 'display_name', "varchar(255) NOT NULL DEFAULT ''", $errors);

        self::addIndex('employee', 'idx_employee_card_id', ['card_id'], $errors);
        self::addIndex('employee', 'idx_employee_open_id', ['open_id'], $errors);
        self::addIndex('guest', 'idx_guest_card_id', ['card_id'], $errors);
        self::addIndex('devices', 'idx_devices_did', ['did'], $errors);
        self::addIndex('devices', 'idx_devices_ip', ['ip'], $errors);
        self::addIndex('user', 'idx_user_open_id', ['open_id'], $errors);

        self::seedDefaults();
        self::cleanupCredentialSettings($errors);
        self::ensureFeishuKeyFile($_config['feishu']['keyConfigFile'] ?? ROOT . '/feishu_key.json');
        Settings::set('schema_version', self::SCHEMA_VERSION);

        return [
            'ok' => count($errors) === 0,
            'message' => count($errors) === 0 ? '迁移完成' : implode("\n", $errors)
        ];
    }

    public static function currentVersion()
    {
        return Settings::get('schema_version', '0');
    }

    private static function seedDefaults()
    {
        global $conn;

        $errors = [];
        foreach (Settings::defaults() as $key => $value) {
            if ($key === 'schema_version') {
                continue;
            }
            $key = mysqli_real_escape_string($conn, $key);
            $value = mysqli_real_escape_string($conn, (string)$value);
            $now = time();
            self::exec("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('{$key}', '{$value}', {$now})", $errors);
        }
        Settings::invalidate();
    }

    private static function cleanupCredentialSettings(&$errors)
    {
        $keys = [
            'feishu_app_id',
            'feishu_app_secret',
            'oa_app_id',
            'oa_app_secret',
            'remote_open_username',
            'remote_open_password',
            'feishu_event_token',
            'feishu_event_encrypt_key',
            'oa_token',
            'oa_token_expires_at'
        ];
        $escaped = [];
        foreach ($keys as $key) {
            $escaped[] = "'" . mysqli_real_escape_string($GLOBALS['conn'], $key) . "'";
        }
        self::exec("DELETE FROM `system_settings` WHERE `setting_key` IN (" . implode(',', $escaped) . ")", $errors);
        Settings::invalidate();
    }

    private static function ensureFeishuKeyFile($path)
    {
        if (!$path || file_exists($path)) {
            return;
        }
        @file_put_contents($path, "{}");
    }

    private static function addColumn($table, $column, $definition, &$errors)
    {
        if (!self::tableExists($table) || self::columnExists($table, $column)) {
            return;
        }
        self::exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}", $errors);
    }

    private static function addIndex($table, $index, $columns, &$errors)
    {
        if (!self::tableExists($table) || self::indexExists($table, $index)) {
            return;
        }

        foreach ($columns as $column) {
            if (!self::columnExists($table, $column)) {
                return;
            }
        }

        $parts = [];
        foreach ($columns as $column) {
            $parts[] = "`{$column}`";
        }
        self::exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` (" . implode(',', $parts) . ")", $errors);
    }

    private static function tableExists($table)
    {
        global $conn;
        $table = mysqli_real_escape_string($conn, $table);
        $rs = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
        return $rs && mysqli_num_rows($rs) > 0;
    }

    private static function columnExists($table, $column)
    {
        global $conn;
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $rs = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $rs && mysqli_num_rows($rs) > 0;
    }

    private static function indexExists($table, $index)
    {
        global $conn;
        $table = mysqli_real_escape_string($conn, $table);
        $index = mysqli_real_escape_string($conn, $index);
        $rs = mysqli_query($conn, "SHOW INDEX FROM `{$table}` WHERE `Key_name`='{$index}'");
        return $rs && mysqli_num_rows($rs) > 0;
    }

    private static function exec($sql, &$errors)
    {
        global $conn;
        if (!mysqli_query($conn, $sql)) {
            $errors[] = mysqli_error($conn) . ' SQL: ' . $sql;
        }
    }
}
