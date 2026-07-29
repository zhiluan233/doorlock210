<?php
/*

数据库迁移与初始化模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

class Migrator {

    const SCHEMA_VERSION = '20260729_attendance_daily_schedule';

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

        self::exec("CREATE TABLE IF NOT EXISTS `feishu_departments` (
            `department_id` varchar(128) NOT NULL,
            `open_department_id` varchar(128) NOT NULL DEFAULT '',
            `parent_department_id` varchar(128) NOT NULL DEFAULT '',
            `name` varchar(255) NOT NULL DEFAULT '',
            `i18n_name` text,
            `leader_user_id` varchar(128) NOT NULL DEFAULT '',
            `member_count` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(32) NOT NULL DEFAULT 'active',
            `raw_payload` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`department_id`),
            KEY `idx_department_parent` (`parent_department_id`),
            KEY `idx_department_open` (`open_department_id`),
            KEY `idx_department_status` (`status`),
            KEY `idx_department_name` (`name`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `access_roles` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `description` varchar(255) NOT NULL DEFAULT '',
            `subject_kind` varchar(20) NOT NULL DEFAULT 'employee',
            `allow_all` tinyint(1) NOT NULL DEFAULT 0,
            `builtin_key` varchar(64) NOT NULL DEFAULT '',
            `expires_at` int unsigned NOT NULL DEFAULT 0,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_access_role_name` (`name`),
            KEY `idx_access_role_enabled` (`enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `learner` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `student_no` varchar(128) NOT NULL,
            `name` varchar(255) NOT NULL DEFAULT '',
            `realname` varchar(255) NOT NULL DEFAULT '',
            `mobile` varchar(64) NOT NULL DEFAULT '',
            `class_name` varchar(255) NOT NULL DEFAULT '',
            `training_center` varchar(255) NOT NULL DEFAULT '',
            `enrolled_at` int unsigned NOT NULL DEFAULT 0,
            `card_id` varchar(64) NOT NULL DEFAULT '',
            `status` varchar(16) NOT NULL DEFAULT 'true',
            `remark` varchar(255) NOT NULL DEFAULT '',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_learner_student_no` (`student_no`),
            KEY `idx_learner_card_id` (`card_id`),
            KEY `idx_learner_status` (`status`),
            KEY `idx_learner_name` (`name`(191)),
            KEY `idx_learner_realname` (`realname`(191)),
            KEY `idx_learner_class_name` (`class_name`(191)),
            KEY `idx_learner_training_center` (`training_center`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `access_role_members` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `role_id` bigint unsigned NOT NULL,
            `member_kind` varchar(20) NOT NULL DEFAULT 'employee',
            `employee_open_id` varchar(128) NOT NULL,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_access_role_member` (`role_id`, `employee_open_id`),
            KEY `idx_access_role_employee` (`employee_open_id`)
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

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_source_records` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `source` varchar(32) NOT NULL DEFAULT '',
            `source_kind` varchar(32) NOT NULL DEFAULT '',
            `external_id` varchar(191) NOT NULL DEFAULT '',
            `event_id` varchar(128) NOT NULL DEFAULT '',
            `employee_open_id` varchar(128) NOT NULL DEFAULT '',
            `employee_user_id` varchar(128) NOT NULL DEFAULT '',
            `employee_no` varchar(128) NOT NULL DEFAULT '',
            `employee_name` varchar(255) NOT NULL DEFAULT '',
            `card_id` varchar(64) NOT NULL DEFAULT '',
            `punch_time` int unsigned NOT NULL DEFAULT 0,
            `punch_date` date NOT NULL,
            `location_name` varchar(255) NOT NULL DEFAULT '',
            `device_name` varchar(255) NOT NULL DEFAULT '',
            `warning_status` varchar(20) NOT NULL DEFAULT 'pending',
            `warning_attempts` int unsigned NOT NULL DEFAULT 0,
            `warning_next_retry` int unsigned NOT NULL DEFAULT 0,
            `warning_sent_at` int unsigned NOT NULL DEFAULT 0,
            `warning_response` text,
            `raw_payload` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attendance_source_external` (`source`, `external_id`),
            KEY `idx_attendance_source_open_time` (`employee_open_id`, `punch_time`),
            KEY `idx_attendance_source_no_time` (`employee_no`, `punch_time`),
            KEY `idx_attendance_source_date_kind` (`punch_date`, `source_kind`),
            KEY `idx_attendance_source_warning` (`warning_status`, `warning_next_retry`, `punch_time`),
            KEY `idx_attendance_source_event` (`event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_effective_records` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `pair_hash` char(64) NOT NULL,
            `person_key` varchar(191) NOT NULL DEFAULT '',
            `employee_open_id` varchar(128) NOT NULL DEFAULT '',
            `employee_user_id` varchar(128) NOT NULL DEFAULT '',
            `employee_no` varchar(128) NOT NULL DEFAULT '',
            `employee_name` varchar(255) NOT NULL DEFAULT '',
            `work_date` date NOT NULL,
            `sequence_no` int unsigned NOT NULL DEFAULT 0,
            `effective_time` int unsigned NOT NULL DEFAULT 0,
            `badge_record_id` bigint unsigned NOT NULL DEFAULT 0,
            `face_record_id` bigint unsigned NOT NULL DEFAULT 0,
            `badge_time` int unsigned NOT NULL DEFAULT 0,
            `face_time` int unsigned NOT NULL DEFAULT 0,
            `interval_seconds` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(32) NOT NULL DEFAULT 'normal',
            `group_id` bigint unsigned NOT NULL DEFAULT 0,
            `group_name` varchar(255) NOT NULL DEFAULT '',
            `location_name` varchar(255) NOT NULL DEFAULT '',
            `device_name` varchar(255) NOT NULL DEFAULT '',
            `rule_snapshot` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attendance_effective_pair` (`pair_hash`),
            KEY `idx_attendance_effective_person_date` (`person_key`, `work_date`),
            KEY `idx_attendance_effective_date_status` (`work_date`, `status`),
            KEY `idx_attendance_effective_time` (`effective_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_effective_message_queue` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `pair_hash` char(64) NOT NULL,
            `person_key` varchar(191) NOT NULL DEFAULT '',
            `employee_open_id` varchar(128) NOT NULL DEFAULT '',
            `employee_user_id` varchar(128) NOT NULL DEFAULT '',
            `employee_no` varchar(128) NOT NULL DEFAULT '',
            `employee_name` varchar(255) NOT NULL DEFAULT '',
            `work_date` date NOT NULL,
            `effective_time` int unsigned NOT NULL DEFAULT 0,
            `badge_time` int unsigned NOT NULL DEFAULT 0,
            `face_time` int unsigned NOT NULL DEFAULT 0,
            `interval_seconds` int unsigned NOT NULL DEFAULT 0,
            `status_text` varchar(32) NOT NULL DEFAULT '',
            `group_name` varchar(255) NOT NULL DEFAULT '',
            `location_name` varchar(255) NOT NULL DEFAULT '',
            `device_name` varchar(255) NOT NULL DEFAULT '',
            `message_status` varchar(20) NOT NULL DEFAULT 'pending',
            `message_attempts` int unsigned NOT NULL DEFAULT 0,
            `message_next_retry` int unsigned NOT NULL DEFAULT 0,
            `message_response` text,
            `raw_payload` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attendance_effective_message_pair` (`pair_hash`),
            KEY `idx_attendance_effective_message_retry` (`message_status`, `message_next_retry`),
            KEY `idx_attendance_effective_message_employee` (`employee_open_id`, `effective_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_daily_reports` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `report_hash` char(64) NOT NULL,
            `person_key` varchar(191) NOT NULL DEFAULT '',
            `employee_open_id` varchar(128) NOT NULL DEFAULT '',
            `employee_user_id` varchar(128) NOT NULL DEFAULT '',
            `employee_no` varchar(128) NOT NULL DEFAULT '',
            `employee_name` varchar(255) NOT NULL DEFAULT '',
            `work_date` date NOT NULL,
            `group_id` bigint unsigned NOT NULL DEFAULT 0,
            `group_name` varchar(255) NOT NULL DEFAULT '',
            `scheduled_start` int unsigned NOT NULL DEFAULT 0,
            `scheduled_end` int unsigned NOT NULL DEFAULT 0,
            `first_effective_at` int unsigned NOT NULL DEFAULT 0,
            `last_effective_at` int unsigned NOT NULL DEFAULT 0,
            `effective_count` int unsigned NOT NULL DEFAULT 0,
            `late_minutes` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(32) NOT NULL DEFAULT 'absent',
            `status_flags` varchar(255) NOT NULL DEFAULT '',
            `status_text` varchar(255) NOT NULL DEFAULT '',
            `work_start_valid` tinyint(1) NOT NULL DEFAULT 0,
            `work_end_valid` tinyint(1) NOT NULL DEFAULT 0,
            `is_late` tinyint(1) NOT NULL DEFAULT 0,
            `is_early_leave` tinyint(1) NOT NULL DEFAULT 0,
            `is_full_absent` tinyint(1) NOT NULL DEFAULT 0,
            `invalid_face_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_badge_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_total` int unsigned NOT NULL DEFAULT 0,
            `invalid_late_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_early_leave_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_late_face_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_late_badge_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_early_leave_face_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_early_leave_badge_count` int unsigned NOT NULL DEFAULT 0,
            `invalid_late_related` tinyint(1) NOT NULL DEFAULT 0,
            `invalid_early_leave_related` tinyint(1) NOT NULL DEFAULT 0,
            `source_updated_at` int unsigned NOT NULL DEFAULT 0,
            `calculated_at` int unsigned NOT NULL DEFAULT 0,
            `raw_trace` mediumtext,
            `oa_status` varchar(20) NOT NULL DEFAULT 'skipped',
            `oa_attempts` int unsigned NOT NULL DEFAULT 0,
            `oa_next_retry` int unsigned NOT NULL DEFAULT 0,
            `oa_response` text,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attendance_daily_report` (`report_hash`),
            KEY `idx_attendance_daily_person_date` (`person_key`, `work_date`),
            KEY `idx_attendance_daily_date_status` (`work_date`, `status`),
            KEY `idx_attendance_daily_flags` (`work_date`, `is_late`, `is_early_leave`, `is_full_absent`),
            KEY `idx_attendance_daily_oa` (`oa_status`, `oa_next_retry`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_groups` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `group_key` varchar(191) NOT NULL DEFAULT '',
            `name` varchar(255) NOT NULL DEFAULT '',
            `source` varchar(32) NOT NULL DEFAULT 'manual',
            `feishu_group_id` varchar(128) NOT NULL DEFAULT '',
            `start_time` varchar(5) NOT NULL DEFAULT '09:30',
            `end_time` varchar(5) NOT NULL DEFAULT '18:30',
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `auto_sync` tinyint(1) NOT NULL DEFAULT 0,
            `member_count` int unsigned NOT NULL DEFAULT 0,
            `raw_payload` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attendance_group_key` (`group_key`),
            KEY `idx_attendance_group_enabled` (`enabled`),
            KEY `idx_attendance_group_feishu` (`feishu_group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_shifts` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `shift_key` varchar(191) NOT NULL DEFAULT '',
            `feishu_shift_id` varchar(128) NOT NULL DEFAULT '',
            `shift_name` varchar(255) NOT NULL DEFAULT '',
            `start_time` varchar(5) NOT NULL DEFAULT '',
            `end_time` varchar(5) NOT NULL DEFAULT '',
            `raw_payload` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attendance_shift_key` (`shift_key`),
            KEY `idx_attendance_shift_feishu` (`feishu_shift_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_group_members` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `group_id` bigint unsigned NOT NULL DEFAULT 0,
            `employee_open_id` varchar(128) NOT NULL DEFAULT '',
            `employee_user_id` varchar(128) NOT NULL DEFAULT '',
            `employee_no` varchar(128) NOT NULL DEFAULT '',
            `employee_name` varchar(255) NOT NULL DEFAULT '',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_attendance_group_member_group` (`group_id`),
            KEY `idx_attendance_group_member_open` (`employee_open_id`),
            KEY `idx_attendance_group_member_user` (`employee_user_id`),
            KEY `idx_attendance_group_member_no` (`employee_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_daily_schedules` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `person_key` varchar(191) NOT NULL DEFAULT '',
            `employee_open_id` varchar(128) NOT NULL DEFAULT '',
            `employee_user_id` varchar(128) NOT NULL DEFAULT '',
            `employee_no` varchar(128) NOT NULL DEFAULT '',
            `employee_name` varchar(255) NOT NULL DEFAULT '',
            `work_date` date NOT NULL,
            `feishu_group_id` varchar(128) NOT NULL DEFAULT '',
            `group_name` varchar(255) NOT NULL DEFAULT '',
            `shift_id` varchar(128) NOT NULL DEFAULT '',
            `shift_name` varchar(255) NOT NULL DEFAULT '',
            `start_time` varchar(5) NOT NULL DEFAULT '',
            `end_time` varchar(5) NOT NULL DEFAULT '',
            `need_punch` tinyint(1) NOT NULL DEFAULT 1,
            `schedule_source` varchar(32) NOT NULL DEFAULT 'feishu',
            `synced_at` int unsigned NOT NULL DEFAULT 0,
            `raw_payload` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_attendance_daily_schedule` (`person_key`, `work_date`),
            KEY `idx_attendance_daily_schedule_date` (`work_date`, `need_punch`),
            KEY `idx_attendance_daily_schedule_group` (`feishu_group_id`, `work_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `attendance_sync_jobs` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `job_type` varchar(40) NOT NULL DEFAULT '',
            `source` varchar(40) NOT NULL DEFAULT '',
            `status` varchar(20) NOT NULL DEFAULT 'pending',
            `date_from` varchar(10) NOT NULL DEFAULT '',
            `date_to` varchar(10) NOT NULL DEFAULT '',
            `total_count` int unsigned NOT NULL DEFAULT 0,
            `processed_count` int unsigned NOT NULL DEFAULT 0,
            `success_count` int unsigned NOT NULL DEFAULT 0,
            `failed_count` int unsigned NOT NULL DEFAULT 0,
            `attempts` int unsigned NOT NULL DEFAULT 0,
            `next_retry` int unsigned NOT NULL DEFAULT 0,
            `locked_at` int unsigned NOT NULL DEFAULT 0,
            `started_at` int unsigned NOT NULL DEFAULT 0,
            `finished_at` int unsigned NOT NULL DEFAULT 0,
            `message` text,
            `raw_payload` mediumtext,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_attendance_job_retry` (`status`, `next_retry`, `id`),
            KEY `idx_attendance_job_type` (`job_type`, `status`, `created_at`),
            KEY `idx_attendance_job_locked` (`locked_at`)
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

        self::exec("CREATE TABLE IF NOT EXISTS `feishu_sync_jobs` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `job_type` varchar(40) NOT NULL DEFAULT 'full_contact',
            `source` varchar(40) NOT NULL DEFAULT 'manual',
            `status` varchar(20) NOT NULL DEFAULT 'pending',
            `requested_by` varchar(128) NOT NULL DEFAULT '',
            `total_count` int unsigned NOT NULL DEFAULT 0,
            `insert_count` int unsigned NOT NULL DEFAULT 0,
            `update_count` int unsigned NOT NULL DEFAULT 0,
            `disable_count` int unsigned NOT NULL DEFAULT 0,
            `delete_count` int unsigned NOT NULL DEFAULT 0,
            `release_count` int unsigned NOT NULL DEFAULT 0,
            `job_title_count` int unsigned NOT NULL DEFAULT 0,
            `joined_at_count` int unsigned NOT NULL DEFAULT 0,
            `message` text,
            `locked_at` int unsigned NOT NULL DEFAULT 0,
            `started_at` int unsigned NOT NULL DEFAULT 0,
            `finished_at` int unsigned NOT NULL DEFAULT 0,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_job_status` (`job_type`, `status`, `created_at`),
            KEY `idx_locked_at` (`locked_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `operation_logs` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint unsigned NOT NULL DEFAULT 0,
            `username` varchar(128) NOT NULL DEFAULT '',
            `display_name` varchar(255) NOT NULL DEFAULT '',
            `role` varchar(32) NOT NULL DEFAULT '',
            `action_code` varchar(64) NOT NULL DEFAULT '',
            `action_name` varchar(128) NOT NULL DEFAULT '',
            `module` varchar(64) NOT NULL DEFAULT '',
            `target_type` varchar(64) NOT NULL DEFAULT '',
            `target_id` varchar(128) NOT NULL DEFAULT '',
            `target_name` varchar(255) NOT NULL DEFAULT '',
            `detail` text,
            `method` varchar(16) NOT NULL DEFAULT '',
            `request_path` varchar(512) NOT NULL DEFAULT '',
            `ip` varchar(64) NOT NULL DEFAULT '',
            `user_agent` varchar(255) NOT NULL DEFAULT '',
            `status_code` int unsigned NOT NULL DEFAULT 200,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_operation_actor_time` (`username`, `created_at`),
            KEY `idx_operation_role_time` (`role`, `created_at`),
            KEY `idx_operation_action_time` (`action_code`, `created_at`),
            KEY `idx_operation_target` (`target_type`, `target_id`),
            KEY `idx_operation_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `device_card_bindings` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `device_id` bigint unsigned NOT NULL,
            `slot_index` int unsigned NOT NULL,
            `subject_kind` varchar(20) NOT NULL,
            `subject_id` varchar(128) NOT NULL,
            `card_id` varchar(64) NOT NULL DEFAULT '',
            `display_name` varchar(100) NOT NULL DEFAULT '',
            `valid_to` int unsigned NOT NULL DEFAULT 0,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `status` varchar(20) NOT NULL DEFAULT 'synced',
            `last_sync_at` int unsigned NOT NULL DEFAULT 0,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_device_card_slot` (`device_id`, `slot_index`),
            UNIQUE KEY `uniq_device_card_subject` (`device_id`, `subject_kind`, `subject_id`),
            KEY `idx_device_card` (`device_id`, `card_id`),
            KEY `idx_device_card_status` (`device_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::exec("CREATE TABLE IF NOT EXISTS `device_card_sync_jobs` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `device_id` bigint unsigned NOT NULL,
            `job_type` varchar(20) NOT NULL DEFAULT 'upsert',
            `subject_kind` varchar(20) NOT NULL DEFAULT '',
            `subject_id` varchar(128) NOT NULL DEFAULT '',
            `card_id` varchar(64) NOT NULL DEFAULT '',
            `slot_index` int unsigned NOT NULL DEFAULT 0,
            `display_name` varchar(100) NOT NULL DEFAULT '',
            `valid_to` int unsigned NOT NULL DEFAULT 0,
            `source` varchar(40) NOT NULL DEFAULT '',
            `status` varchar(20) NOT NULL DEFAULT 'pending',
            `attempts` int unsigned NOT NULL DEFAULT 0,
            `next_retry` int unsigned NOT NULL DEFAULT 0,
            `locked_at` int unsigned NOT NULL DEFAULT 0,
            `message` text,
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            `finished_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_device_card_job_retry` (`status`, `next_retry`, `id`),
            KEY `idx_device_card_job_device` (`device_id`, `status`),
            KEY `idx_device_card_job_subject` (`subject_kind`, `subject_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $errors);

        self::addColumn('feishu_sync_jobs', 'delete_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('feishu_sync_jobs', 'job_title_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('feishu_sync_jobs', 'joined_at_count', "int unsigned NOT NULL DEFAULT 0", $errors);

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
        self::addColumn('employee', 'avatar_url', "varchar(512) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'job_title', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('employee', 'joined_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('employee', 'updated_at', "int unsigned NOT NULL DEFAULT 0", $errors);

        self::addColumn('guest', 'card_id', "varchar(64) NOT NULL DEFAULT ''", $errors);
        self::addColumn('guest', 'external_subject', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('guest', 'expires_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('guest', 'inviter_open_id', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('guest', 'inviter_name', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('guest', 'inviter_department_id', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('guest', 'inviter_department_name', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('guest', 'updated_at', "int unsigned NOT NULL DEFAULT 0", $errors);

        self::addColumn('learner', 'student_no', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'name', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'realname', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'mobile', "varchar(64) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'class_name', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'training_center', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'enrolled_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('learner', 'card_id', "varchar(64) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'status', "varchar(16) NOT NULL DEFAULT 'true'", $errors);
        self::addColumn('learner', 'remark', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('learner', 'created_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('learner', 'updated_at', "int unsigned NOT NULL DEFAULT 0", $errors);

        self::addColumn('access_roles', 'subject_kind', "varchar(20) NOT NULL DEFAULT 'employee'", $errors);
        self::addColumn('access_roles', 'builtin_key', "varchar(64) NOT NULL DEFAULT ''", $errors);
        self::addColumn('access_roles', 'expires_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('access_role_members', 'member_kind', "varchar(20) NOT NULL DEFAULT 'employee'", $errors);
        self::addColumn('attendance_source_records', 'warning_status', "varchar(20) NOT NULL DEFAULT 'pending'", $errors);
        self::addColumn('attendance_source_records', 'warning_attempts', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_source_records', 'warning_next_retry', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_source_records', 'warning_sent_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_source_records', 'warning_response', "text", $errors);
        self::addColumn('attendance_effective_records', 'location_name', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('attendance_effective_records', 'device_name', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('attendance_daily_reports', 'status_flags', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('attendance_daily_reports', 'status_text', "varchar(255) NOT NULL DEFAULT ''", $errors);
        self::addColumn('attendance_daily_reports', 'work_start_valid', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'work_end_valid', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'is_late', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'is_early_leave', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'is_full_absent', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_face_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_badge_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_total', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_late_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_early_leave_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_late_face_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_late_badge_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_early_leave_face_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_early_leave_badge_count', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_late_related', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('attendance_daily_reports', 'invalid_early_leave_related', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('device_card_bindings', 'valid_to', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('device_card_sync_jobs', 'valid_to', "int unsigned NOT NULL DEFAULT 0", $errors);

        self::addColumn('devices', 'allowedEmployee', "longtext", $errors);
        self::addColumn('devices', 'allowedGuest', "longtext", $errors);
        self::addColumn('devices', 'dtype', "varchar(32) NOT NULL DEFAULT 'card_http'", $errors);
        self::addColumn('devices', 'device_sn', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'serial', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'model', "varchar(128) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'controller_type', "varchar(32) NOT NULL DEFAULT ''", $errors);
        self::addColumn('devices', 'local_card_enabled', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('devices', 'local_card_initial_full_done', "tinyint(1) NOT NULL DEFAULT 0", $errors);
        self::addColumn('devices', 'local_card_last_full_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('devices', 'local_card_last_sync_at', "int unsigned NOT NULL DEFAULT 0", $errors);
        self::addColumn('devices', 'local_card_sync_message', "varchar(255) NOT NULL DEFAULT ''", $errors);
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
        self::addIndex('guest', 'idx_guest_expires_at', ['expires_at'], $errors);
        self::addIndex('guest', 'idx_guest_inviter_open_id', ['inviter_open_id'], $errors);
        self::addIndex('learner', 'idx_learner_card_id', ['card_id'], $errors);
        self::addIndex('learner', 'idx_learner_status', ['status'], $errors);
        self::addIndex('learner', 'idx_learner_name', [['name' => 'name', 'length' => 191]], $errors);
        self::addIndex('learner', 'idx_learner_realname', [['name' => 'realname', 'length' => 191]], $errors);
        self::addIndex('learner', 'idx_learner_class_name', [['name' => 'class_name', 'length' => 191]], $errors);
        self::addIndex('learner', 'idx_learner_training_center', [['name' => 'training_center', 'length' => 191]], $errors);
        self::addIndex('devices', 'idx_devices_did', ['did'], $errors);
        self::addIndex('devices', 'idx_devices_ip', ['ip'], $errors);
        self::addIndex('user', 'idx_user_open_id', ['open_id'], $errors);
        self::addIndex('access_roles', 'idx_access_role_subject', ['subject_kind'], $errors);
        self::addIndex('access_roles', 'idx_access_role_builtin', ['builtin_key'], $errors);
        self::addIndex('access_roles', 'idx_access_role_expires_at', ['expires_at'], $errors);
        self::addIndex('access_role_members', 'idx_access_role_member_kind', ['member_kind'], $errors);
        self::addIndex('attendance_source_records', 'idx_attendance_source_warning', ['warning_status', 'warning_next_retry', 'punch_time'], $errors);
        self::addIndex('attendance_daily_reports', 'idx_attendance_daily_flags', ['work_date', 'is_late', 'is_early_leave', 'is_full_absent'], $errors);

        self::seedDefaults();
        self::normalizeRuntimeSettings($errors);
        self::seedDefaultAccessRoles($errors);
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

    private static function normalizeRuntimeSettings(&$errors)
    {
        self::exec("UPDATE `system_settings` SET `setting_value`='刷卡成功' WHERE `setting_key`='feishu_message_template' AND `setting_value`='打卡成功：{name} 于 {time} 在 {door} 完成刷卡。'", $errors);
        self::exec("UPDATE `system_settings` SET `setting_value`='flow' WHERE `setting_key`='feishu_attendance_mode' AND `setting_value`='remedy'", $errors);
        self::exec("UPDATE `system_settings` SET `setting_value`='/cdor.cgi?open=1&door=0?' WHERE `setting_key`='remote_open_path' AND `setting_value`='/cdor.cgi?open=0'", $errors);
        self::exec("UPDATE `employee` SET `card_id`=LPAD(`card_id`, 10, '0') WHERE `card_id`<>'' AND `card_id` REGEXP '^[0-9]+$' AND CHAR_LENGTH(`card_id`)<10", $errors);
        self::exec("UPDATE `guest` SET `card_id`=LPAD(`card_id`, 10, '0') WHERE `card_id`<>'' AND `card_id` REGEXP '^[0-9]+$' AND CHAR_LENGTH(`card_id`)<10", $errors);
        self::exec("UPDATE `learner` SET `card_id`=LPAD(`card_id`, 10, '0') WHERE `card_id`<>'' AND `card_id` REGEXP '^[0-9]+$' AND CHAR_LENGTH(`card_id`)<10", $errors);
        self::exec("UPDATE `learner` SET `realname`=`name` WHERE `realname`=''", $errors);
        self::exec("UPDATE `learner` SET `enrolled_at`=`created_at` WHERE `enrolled_at`=0 AND `created_at`>0", $errors);
        self::exec("UPDATE `logs` SET `cardid`=LPAD(`cardid`, 10, '0') WHERE `cardid`<>'' AND `cardid` REGEXP '^[0-9]+$' AND CHAR_LENGTH(`cardid`)<10", $errors);
        self::exec("UPDATE `devices` SET `serial`=`did` WHERE `serial`='' AND `did`<>''", $errors);
        self::exec("UPDATE `devices` SET `controller_type`='single_door' WHERE `controller_type`='' AND (`model` LIKE '%G-1000%' OR `model` LIKE '%D110%')", $errors);
        self::exec("UPDATE `devices` SET `controller_type`='cloud_plus' WHERE `controller_type`='' AND (`model` LIKE '%G-Cloud%' OR `model` LIKE '%Cloud%')", $errors);
        self::exec("UPDATE `access_roles` SET `subject_kind`='employee' WHERE `subject_kind`=''", $errors);
        self::exec("UPDATE `access_role_members` SET `member_kind`='employee' WHERE `member_kind`=''", $errors);
        $lastIncrementalEvent = strtolower(Settings::get('feishu_contact_incremental_last_event', ''));
        if (preg_match('/^(attendance|approval|calendar|im|message|task|doc|drive|meeting|vc)\./', $lastIncrementalEvent)) {
            Settings::set('feishu_contact_incremental_last_at', '0');
            Settings::set('feishu_contact_incremental_last_event', '');
        }
        Settings::invalidate();
    }

    private static function seedDefaultAccessRoles(&$errors)
    {
        $now = time();
        $roles = [
            [
                'name' => '全体员工角色',
                'description' => '系统内置角色，动态包含所有启用员工',
                'subject_kind' => 'employee',
                'builtin_key' => 'all_employee'
            ],
            [
                'name' => '全体学员角色',
                'description' => '系统内置角色，动态包含所有启用学员',
                'subject_kind' => 'learner',
                'builtin_key' => 'all_learner'
            ],
            [
                'name' => '全体访客角色',
                'description' => '系统内置角色，动态包含所有启用访客',
                'subject_kind' => 'guest',
                'builtin_key' => 'all_guest'
            ]
        ];

        foreach ($roles as $role) {
            $name = Database::escape($role['name']);
            $description = Database::escape($role['description']);
            $subjectKind = Database::escape($role['subject_kind']);
            $builtinKey = Database::escape($role['builtin_key']);
            self::exec("INSERT INTO `access_roles` (`name`, `description`, `subject_kind`, `allow_all`, `builtin_key`, `expires_at`, `enabled`, `created_at`, `updated_at`) SELECT '{$name}', '{$description}', '{$subjectKind}', 1, '{$builtinKey}', 0, 1, {$now}, {$now} WHERE NOT EXISTS (SELECT 1 FROM `access_roles` WHERE `builtin_key`='{$builtinKey}' OR `name`='{$name}')", $errors);
            self::exec("UPDATE `access_roles` SET `description`='{$description}', `subject_kind`='{$subjectKind}', `allow_all`=1, `builtin_key`='{$builtinKey}', `expires_at`=0, `enabled`=1, `updated_at`={$now} WHERE `builtin_key`='{$builtinKey}' OR `name`='{$name}'", $errors);
        }
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
            'oa_token_expires_at',
            'feishu_oauth_authorize_url',
            'feishu_attendance_endpoint',
            'feishu_attendance_flow_comment'
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
            $columnName = is_array($column) ? ($column['name'] ?? '') : $column;
            if (!self::columnExists($table, $columnName)) {
                return;
            }
        }

        $parts = [];
        foreach ($columns as $column) {
            $columnName = is_array($column) ? ($column['name'] ?? '') : $column;
            $length = is_array($column) ? intval($column['length'] ?? 0) : self::indexPrefixLength($table, $columnName);
            $parts[] = "`{$columnName}`" . ($length > 0 ? "({$length})" : "");
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

    private static function indexPrefixLength($table, $column)
    {
        global $conn;
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $rs = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if (!$rs || mysqli_num_rows($rs) === 0) {
            return 0;
        }

        $info = mysqli_fetch_assoc($rs);
        $type = strtolower($info['Type'] ?? '');
        if (preg_match('/blob|text/', $type)) {
            return 191;
        }
        return 0;
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
