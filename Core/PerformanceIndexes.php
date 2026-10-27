<?php
namespace anim210System;

// 大表索引单独由 CLI 部署，不在页面、刷卡回调或 worker 启动时执行 DDL。
class PerformanceIndexes {

    public static function definitions()
    {
        return [
            ['logs', 'idx_logs_time', ['time']],
            ['logs', 'idx_logs_card_time', ['cardid', 'time']],
            ['employee', 'idx_employee_employee_id', ['employee_id']],
            ['employee', 'idx_employee_user_id', ['user_id']],
            ['attendance_source_records', 'idx_attendance_source_user_time', ['employee_user_id', 'punch_time']],
            ['attendance_source_records', 'idx_attendance_source_warning_time', ['warning_status', 'punch_time', 'warning_next_retry']],
            ['attendance_source_records', 'idx_attendance_source_location', ['source', 'location_name']],
            ['attendance_effective_records', 'idx_attendance_effective_badge', ['badge_record_id']],
            ['attendance_effective_records', 'idx_attendance_effective_face', ['face_record_id']],
            ['attendance_sync_jobs', 'idx_attendance_job_stale', ['status', 'locked_at']],
            ['device_card_bindings', 'idx_device_card_subject_lookup', ['subject_kind', 'subject_id']],
            ['attendance_source_records','idx_attendance_source_archive_time',['punch_time']],
            ['attendance_queue','idx_attendance_queue_archive_time',['punch_time']],
            ['feishu_event_log','idx_feishu_event_archive_time',['created_at']],
            ['attendance_effective_message_queue','idx_effective_message_archive_time',['effective_time']],
            ['attendance_sync_jobs','idx_attendance_job_archive_time',['created_at']],
            ['attendance_daily_reports','idx_attendance_daily_archive_date',['work_date','id']],
            ['focus_card_message_queue','idx_focus_card_archive_time',['swipe_time']],
            ['device_card_sync_jobs','idx_device_card_archive_time',['created_at']]
        ];
    }

    public static function run($apply = false)
    {
        global $conn;
        $results = [];
        $ok = true;
        if ($apply) {
            // 等待元数据锁至多 5 秒，避免部署 DDL 长时间阻塞刷卡请求。
            self::query('SET SESSION lock_wait_timeout=5');
        }
        $logTimeColumn = 'time';
        try {
            $logFields = self::rows('SHOW COLUMNS FROM `logs`');
            $types = array_column($logFields, 'Type', 'Field');
            if (preg_match('/text|char/', $types['time'] ?? '')) {
                $logTimeColumn = 'time_index';
                if (!isset($types[$logTimeColumn])) {
                    // 只转换最多 10 位的 ASCII 数字；其他旧值保持 NULL 并由原条件兜底。
                    // 不使用 REGEXP 生成列，以兼容 MySQL 8.0.24 的函数限制。
                    $digitsRemoved = '`time`';
                    for ($digit = 0; $digit <= 9; $digit++) {
                        $digitsRemoved = "REPLACE({$digitsRemoved}, '{$digit}', '')";
                    }
                    $sql = "ALTER TABLE `logs` ADD COLUMN `time_index` bigint unsigned GENERATED ALWAYS AS (CASE WHEN CHAR_LENGTH(`time`) BETWEEN 1 AND 10 AND {$digitsRemoved}='' THEN CAST(`time` AS UNSIGNED) ELSE NULL END) VIRTUAL, ALGORITHM=INPLACE, LOCK=NONE";
                    $item = ['table' => 'logs', 'column' => 'time_index', 'sql' => $sql, 'status' => 'missing'];
                    if ($apply) { self::query($sql); $item['status'] = 'created'; }
                    $results[] = $item;
                }
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => $apply ? 'apply' : 'check', 'indexes' => [], 'message' => $e->getMessage()];
        }
        foreach (self::definitions() as [$table, $name, $columns]) {
            if ($table === 'logs') {
                $columns = array_map(function ($column) use ($logTimeColumn) { return $column === 'time' ? $logTimeColumn : $column; }, $columns);
                if ($logTimeColumn === 'time_index') { $name = str_replace('_time', '_numeric_time', $name); }
            }
            $item = ['table' => $table, 'index' => $name, 'columns' => $columns];
            try {
                $indexes = self::rows("SHOW INDEX FROM `{$table}`");
                $coveredBy = self::coveringIndex($indexes, $columns);
                if ($coveredBy !== null) {
                    $item['status'] = 'present';
                    $item['covered_by'] = $coveredBy;
                } else {
                    foreach ($indexes as $index) {
                        if ($index['Key_name'] === $name) {
                            throw new \RuntimeException('同名索引的字段不同，需要人工核对；不会删除或覆盖');
                        }
                    }
                    $fields = [];
                    foreach (self::rows("SHOW COLUMNS FROM `{$table}`") as $field) {
                        $fields[$field['Field']] = strtolower($field['Type']);
                    }
                    if ($table === 'logs' && !$apply && $logTimeColumn === 'time_index') {
                        $fields['time_index'] = 'bigint unsigned';
                    }
                    $parts = [];
                    foreach ($columns as $column) {
                        if (!isset($fields[$column])) {
                            throw new \RuntimeException('缺少字段：' . $column);
                        }
                        $prefix = preg_match('/text|blob/', $fields[$column]) ? '(191)' : '';
                        $parts[] = "`{$column}`{$prefix}";
                    }
                    $item['sql'] = "ALTER TABLE `{$table}` ADD INDEX `{$name}` (" . implode(',', $parts) . '), ALGORITHM=INPLACE, LOCK=NONE';
                    $item['status'] = 'missing';
                    if ($apply) {
                        // 如服务器无法在线创建索引则失败，不退回锁表方式。
                        self::query($item['sql']);
                        $item['status'] = 'created';
                    }
                }
            } catch (\Throwable $e) {
                $ok = false;
                $item['status'] = 'failed';
                $item['message'] = $e->getMessage();
            }
            $results[] = $item;
        }
        return ['ok' => $ok, 'mode' => $apply ? 'apply' : 'check', 'indexes' => $results];
    }

    private static function coveringIndex($rows, $columns)
    {
        $indexes = [];
        foreach ($rows as $row) {
            if (($row['Index_type'] ?? '') !== 'BTREE' || ($row['Visible'] ?? 'YES') === 'NO') {
                continue;
            }
            // 兼容旧 logs 的 TEXT 卡号；本工具为该字段使用 191 字符前缀。
            $fullColumn = empty($row['Sub_part']) || intval($row['Sub_part']) >= 191;
            $indexes[$row['Key_name']][intval($row['Seq_in_index'])] = $fullColumn ? $row['Column_name'] : null;
        }
        foreach ($indexes as $name => $parts) {
            ksort($parts);
            if (array_slice(array_values($parts), 0, count($columns)) === $columns) {
                return $name;
            }
        }
        return null;
    }

    private static function query($sql)
    {
        global $conn;
        $result = mysqli_query($conn, $sql);
        if ($result === false) {
            throw new \RuntimeException(mysqli_error($conn));
        }
        return $result;
    }

    private static function rows($sql)
    {
        $result = self::query($sql);
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_free_result($result);
        return $rows;
    }
}
