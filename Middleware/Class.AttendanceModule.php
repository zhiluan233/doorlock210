<?php
/*

考勤有效性计算与飞书假勤同步模块
Ver 1.0.0.0 20260728
Code by Jason / Codex

*/

namespace anim210System;

class AttendanceModuleService {

    private static $workerScheduled = false;

    public static function enabled()
    {
        return Settings::getBool('attendance_module_enabled');
    }

    public static function ingestBadgeSwipe($employeeInfo, $deviceInfo, $cardId, $eventTime = null, $eventHash = '')
    {
        if (!self::enabled()) {
            return ['ok' => true, 'skipped' => true, 'message' => '考勤模块未启用'];
        }

        $eventTime = $eventTime ?: time();
        $openId = trim((string)($employeeInfo['open_id'] ?? ''));
        $employeeNo = trim((string)($employeeInfo['employee_id'] ?? ''));
        $userId = trim((string)($employeeInfo['user_id'] ?? ''));
        if ($openId === '' && $employeeNo === '' && $userId === '') {
            return ['ok' => false, 'message' => '员工身份为空'];
        }
        if ($eventHash === '') {
            $eventHash = hash('sha256', implode('|', ['badge', $openId, $employeeNo, $cardId, $eventTime, $deviceInfo['id'] ?? '']));
        }

        $recordId = self::upsertSourceRecord([
            'source' => 'badge',
            'source_kind' => 'badge',
            'external_id' => $eventHash,
            'event_id' => '',
            'employee_open_id' => $openId,
            'employee_user_id' => $userId,
            'employee_no' => $employeeNo,
            'employee_name' => $employeeInfo['name'] ?? '',
            'card_id' => $cardId,
            'punch_time' => $eventTime,
            'punch_date' => date('Y-m-d', $eventTime),
            'location_name' => AttendanceService::externalAttendanceLocationForModule([
                'location' => $deviceInfo['name'] ?? '',
                'door_name' => $deviceInfo['name'] ?? ''
            ]),
            'device_name' => $deviceInfo['name'] ?? '',
            'raw_payload' => [
                'employee' => self::limitedSubjectSnapshot($employeeInfo),
                'device' => self::limitedSubjectSnapshot($deviceInfo),
                'card_id' => $cardId
            ]
        ]);
        if ($recordId > 0) {
            self::enqueueRecalculateForRecord($recordId, 'badge_swipe');
        }
        return ['ok' => true, 'record_id' => $recordId];
    }

    public static function handleFeishuEvent($payload, $eventType, $eventId = '')
    {
        if (!self::enabled()) {
            return '';
        }
        $eventTypeText = strtolower((string)$eventType);
        $payloadText = strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE));
        if (strpos($eventTypeText, 'attendance') === false && strpos($payloadText, 'user_flow') === false && strpos($payloadText, 'attendance') === false) {
            return '';
        }

        $records = self::extractRecordsFromEvent($payload);
        $firstOpenId = '';
        foreach ($records as $record) {
            $record['event_id'] = $eventId;
            $flowId = self::flowIdFromRecord($record);
            if (self::recordHasEnoughFields($record)) {
                if (self::flowLocationNeedsFetch($record) && $flowId !== '') {
                    self::enqueueFetchFlow($flowId, $eventId, 'feishu_event');
                    continue;
                }
                $recordId = self::ingestFeishuFlowRecord($record, $payload);
                if ($recordId > 0) {
                    self::enqueueRecalculateForRecord($recordId, 'feishu_event');
                }
                if ($firstOpenId === '') {
                    $firstOpenId = $record['employee_open_id'] ?? '';
                }
                continue;
            }
            if ($flowId !== '') {
                self::enqueueFetchFlow($flowId, $eventId, 'feishu_event');
            }
        }

        return $firstOpenId;
    }

    public static function scheduleFullSyncIfDue()
    {
        if (!self::enabled()) {
            return ['ok' => true, 'scheduled' => false, 'message' => '考勤模块未启用'];
        }
        if (!Settings::getBool('attendance_full_sync_enabled', true)) {
            return ['ok' => true, 'scheduled' => false, 'message' => '考勤全量同步未启用'];
        }

        $times = self::configuredTimes(Settings::get('attendance_full_sync_times', '13:00,13:30,14:00'));
        if (count($times) === 0) {
            return ['ok' => true, 'scheduled' => false, 'message' => '未配置考勤全量同步时间'];
        }
        $nowTime = date('H:i');
        $today = date('Y-m-d');
        $scheduled = [];
        foreach ($times as $timeText) {
            if ($nowTime !== $timeText) {
                continue;
            }
            $settingKey = 'attendance_full_sync_last_' . str_replace(':', '', $timeText);
            if (Settings::get($settingKey, '') === $today) {
                continue;
            }
            $dateTo = $today;
            $dateFrom = $today;
            if (Settings::get('attendance_group_sync_last_date', '') !== $today) {
                $groupJob = self::enqueueJob('group_sync', 'schedule_' . $timeText, '', '', []);
                if (!empty($groupJob['ok'])) {
                    Settings::set('attendance_group_sync_last_date', $today);
                }
            }
            $job = self::enqueueJob('full_flow', 'schedule_' . $timeText, $dateFrom, $dateTo, []);
            if (!empty($job['ok'])) {
                Settings::set($settingKey, $today);
                $scheduled[] = $job;
            }
        }
        if (count($scheduled) > 0) {
            self::ensureWorkerRunning();
        }
        return ['ok' => true, 'scheduled' => count($scheduled) > 0, 'jobs' => $scheduled];
    }

    public static function enqueueManualFullSync($dateFrom, $dateTo, $source = 'manual')
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => '考勤模块未启用'];
        }
        $dateFrom = self::normalizeDate($dateFrom, date('Y-m-d'));
        $dateTo = self::normalizeDate($dateTo, $dateFrom);
        if (strtotime($dateFrom) > strtotime($dateTo)) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }
        $job = self::enqueueJob('full_flow', $source, $dateFrom, $dateTo, []);
        if (!empty($job['ok'])) {
            self::ensureWorkerRunning();
        }
        return $job;
    }

    public static function enqueueManualReportRecalculate($dateFrom, $dateTo, $source = 'manual_report_recalculate')
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => '考勤模块未启用'];
        }
        $dateFrom = self::normalizeDate($dateFrom, date('Y-m-d'));
        $dateTo = self::normalizeDate($dateTo, $dateFrom);
        if (strtotime($dateFrom) > strtotime($dateTo)) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }
        $dates = [];
        for ($cursor = strtotime($dateFrom); $cursor !== false && $cursor <= strtotime($dateTo); $cursor = strtotime(date('Y-m-d', $cursor) . ' +1 day')) {
            $date = date('Y-m-d', $cursor);
            $job = self::enqueueJob('recalculate_date', $source, $date, $date, ['offset' => 0, 'manual_range' => [$dateFrom, $dateTo]]);
            if (!empty($job['ok'])) {
                $dates[] = $date;
            }
        }
        if (count($dates) > 0) {
            self::ensureWorkerRunning();
        }
        return [
            'ok' => true,
            'scheduled' => count($dates),
            'message' => '已提交考勤日报重算任务：' . count($dates) . ' 天',
            'dates' => $dates
        ];
    }

    public static function enqueueGroupSync($source = 'manual')
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => '考勤模块未启用'];
        }
        $job = self::enqueueJob('group_sync', $source, '', '', []);
        if (!empty($job['ok'])) {
            self::ensureWorkerRunning();
        }
        return $job;
    }

    public static function processCron()
    {
        return [
            'schedule' => self::scheduleFullSyncIfDue(),
            'today_recalculate' => self::scheduleTodayRelationRecalculateIfNeeded(),
            'worker' => self::ensureWorkerRunning(),
            'repair_locations' => self::repairMissingLocationNames(500),
            'message' => self::processEffectiveMessageQueue(Settings::getInt('attendance_effective_message_batch_size', 50)),
            'incomplete_message' => self::processIncompleteMessageQueue(Settings::getInt('attendance_incomplete_message_batch_size', 50)),
            'oa' => self::processOaQueue(Settings::getInt('attendance_oa_batch_size', 100))
        ];
    }

    private static function scheduleTodayRelationRecalculateIfNeeded()
    {
        if (!self::enabled()) {
            return ['ok' => true, 'scheduled' => false, 'message' => '考勤模块未启用'];
        }
        $version = '20260729_invalid_relation_v2';
        if (Settings::get('attendance_report_relation_fix_version', '') === $version) {
            return ['ok' => true, 'scheduled' => false, 'message' => '今日日报关联字段已修正'];
        }
        $today = date('Y-m-d');
        $job = self::enqueueJob('recalculate_date', 'auto_today_relation_fix', $today, $today, [
            'offset' => 0,
            'reason' => 'refresh_invalid_attendance_relation'
        ]);
        if (!empty($job['ok'])) {
            Settings::set('attendance_report_relation_fix_version', $version);
            return ['ok' => true, 'scheduled' => true, 'job_id' => intval($job['job_id'] ?? 0), 'message' => '已提交今日日报关联字段重算'];
        }
        return ['ok' => false, 'scheduled' => false, 'message' => $job['message'] ?? '提交今日日报重算失败'];
    }

    public static function ensureWorkerRunning()
    {
        if (!self::enabled()) {
            return ['ok' => true, 'started' => false, 'message' => '考勤模块未启用'];
        }
        if (self::workerLocked()) {
            return array_merge(['ok' => true, 'started' => false, 'message' => '考勤 worker 正在执行'], self::workerStatus());
        }
        if (!self::hasDueJobs()) {
            return array_merge(['ok' => true, 'started' => false, 'message' => '暂无到期考勤任务'], self::workerStatus());
        }

        $script = rtrim(defined('ROOT') ? ROOT : dirname(__DIR__), '/\\') . '/attendance_worker.php';
        $php = self::phpBinary();
        if (is_file($script) && self::functionAvailable('exec')) {
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
            @exec($cmd);
            return array_merge(['ok' => true, 'started' => true, 'message' => '考勤 worker 已启动'], self::workerStatus());
        }

        if (!self::$workerScheduled) {
            self::$workerScheduled = true;
            register_shutdown_function(function() {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                AttendanceModuleService::runWorker(25);
            });
        }
        return array_merge(['ok' => true, 'started' => true, 'message' => '考勤 worker 将在请求结束后执行'], self::workerStatus());
    }

    public static function runWorker($maxSeconds = 0)
    {
        $lock = self::acquireWorkerLock();
        if (!$lock['ok']) {
            return array_merge(['ok' => true, 'message' => '考勤 worker 已在运行'], self::workerStatus());
        }

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $startedAt = time();
        $total = 0;
        $done = 0;
        $failed = 0;
        $last = null;
        try {
            do {
                $last = self::processNextJob();
                if (empty($last['claimed'])) {
                    break;
                }
                $total++;
                if (!empty($last['done'])) {
                    $done++;
                }
                if (empty($last['ok'])) {
                    $failed++;
                }
                if ($maxSeconds > 0 && time() - $startedAt >= $maxSeconds) {
                    break;
                }
            } while (true);
        } finally {
            self::releaseWorkerLock($lock);
        }

        return array_merge([
            'ok' => true,
            'processed' => $total,
            'done' => $done,
            'failed' => $failed,
            'last' => $last,
            'message' => '考勤 worker 执行完成'
        ], self::workerStatus());
    }

    public static function workerStatus()
    {
        $summary = [
            'worker_running' => self::workerLocked(),
            'pending' => 0,
            'running' => 0,
            'failed' => 0
        ];
        $rs = Database::query('attendance_sync_jobs', "SELECT `status`, COUNT(*) AS `total` FROM `attendance_sync_jobs` WHERE `status` IN ('pending','running','failed') GROUP BY `status`", '', true);
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $status = (string)$row['status'];
                if (isset($summary[$status])) {
                    $summary[$status] = intval($row['total'] ?? 0);
                }
            }
            mysqli_free_result($rs);
        }
        return $summary;
    }

    public static function reportRows($filters, $limit = 50, $offset = 0)
    {
        $where = self::reportWhere($filters);
        $limit = max(1, min(500, intval($limit)));
        $offset = max(0, intval($offset));
        $sql = "SELECT * FROM `attendance_daily_reports` {$where} ORDER BY `work_date` DESC, `employee_name` ASC LIMIT {$offset}, {$limit}";
        return self::fetchRows('attendance_daily_reports', $sql);
    }

    public static function reportCount($filters)
    {
        $where = self::reportWhere($filters);
        $row = Database::querySingleLine('attendance_daily_reports', "SELECT COUNT(*) AS `total` FROM `attendance_daily_reports` {$where}", true);
        return intval($row['total'] ?? 0);
    }

    public static function reportSummary($filters)
    {
        $where = self::reportWhere($filters);
        $row = Database::querySingleLine('attendance_daily_reports', "SELECT COUNT(*) AS `total`, SUM(CASE WHEN `status`='normal' THEN 1 ELSE 0 END) AS `normal_total`, SUM(`is_late`) AS `late_total`, SUM(`is_early_leave`) AS `early_leave_total`, SUM(`is_full_absent`) AS `full_absent_total`, SUM(`work_start_valid`) AS `work_start_valid_total`, SUM(`work_end_valid`) AS `work_end_valid_total`, SUM(`invalid_total`) AS `invalid_total`, SUM(`invalid_face_count`) AS `invalid_face_total`, SUM(`invalid_badge_count`) AS `invalid_badge_total`, SUM(`invalid_late_count`) AS `invalid_late_count_total`, SUM(`invalid_early_leave_count`) AS `invalid_early_leave_count_total`, SUM(`invalid_late_face_count`) AS `invalid_late_face_total`, SUM(`invalid_late_badge_count`) AS `invalid_late_badge_total`, SUM(`invalid_early_leave_face_count`) AS `invalid_early_leave_face_total`, SUM(`invalid_early_leave_badge_count`) AS `invalid_early_leave_badge_total`, SUM(`invalid_late_related`) AS `invalid_late_related_total`, SUM(`invalid_early_leave_related`) AS `invalid_early_leave_related_total`, SUM(`effective_count`) AS `effective_total` FROM `attendance_daily_reports` {$where}", true);
        return is_array($row) ? $row : [];
    }

    public static function sourceRecordsForReport($reportId)
    {
        $report = Database::querySingleLine('attendance_daily_reports', ['id' => intval($reportId)]);
        if (!$report) {
            return ['report' => null, 'sources' => [], 'effective' => []];
        }
        $personKey = self::personKeyFromReport($report);
        $date = $report['work_date'] ?? '';
        $safePerson = Database::escape($personKey);
        $safeDate = Database::escape($date);
        $sources = self::fetchRows('attendance_source_records', "SELECT * FROM `attendance_source_records` WHERE `punch_date`='{$safeDate}' AND (CONCAT('open:', `employee_open_id`)='{$safePerson}' OR CONCAT('no:', `employee_no`)='{$safePerson}' OR CONCAT('user:', `employee_user_id`)='{$safePerson}') ORDER BY `punch_time` ASC, `id` ASC");
        $effective = self::fetchRows('attendance_effective_records', "SELECT * FROM `attendance_effective_records` WHERE `work_date`='{$safeDate}' AND `person_key`='{$safePerson}' ORDER BY `effective_time` ASC, `id` ASC");
        return ['report' => $report, 'sources' => $sources, 'effective' => $effective];
    }

    public static function exportReportsXml($filters)
    {
        $fields = self::exportFields();
        $rows = self::reportRows($filters, 5000, 0);
        $headers = [
            'work_date' => '日期',
            'employee_name' => '花名',
            'employee_no' => '工号',
            'group_name' => '考勤组',
            'scheduled_start' => '应上班',
            'scheduled_end' => '应下班',
            'first_effective_at' => '首次有效考勤',
            'last_effective_at' => '最后有效考勤',
            'effective_count' => '有效次数',
            'status' => '状态',
            'status_text' => '状态明细',
            'work_start_valid' => '上班有效',
            'work_end_valid' => '下班有效',
            'is_late' => '迟到',
            'is_early_leave' => '早退',
            'is_full_absent' => '完全缺勤',
            'invalid_face_count' => '只刷脸次数',
            'invalid_badge_count' => '只刷卡次数',
            'invalid_total' => '无效考勤次数',
            'invalid_late_count' => '上班时间及以前无效次数',
            'invalid_late_face_count' => '上班时间及以前只刷脸次数',
            'invalid_late_badge_count' => '上班时间及以前只刷卡次数',
            'invalid_early_leave_count' => '下班后无效次数',
            'invalid_early_leave_face_count' => '下班后只刷脸次数',
            'invalid_early_leave_badge_count' => '下班后只刷卡次数',
            'invalid_late_related' => '上班单边验证导致迟到',
            'invalid_early_leave_related' => '下班单边验证导致早退',
            'late_minutes' => '迟到分钟',
            'updated_at' => '更新时间',
            'trace' => '溯源摘要'
        ];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="考勤日报"><Table>';
        $xml .= '<Row>';
        foreach ($fields as $field) {
            $xml .= '<Cell><Data ss:Type="String">' . self::xml($headers[$field] ?? $field) . '</Data></Cell>';
        }
        $xml .= '</Row>';
        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($fields as $field) {
                $value = self::exportValue($row, $field);
                $xml .= '<Cell><Data ss:Type="String">' . self::xml($value) . '</Data></Cell>';
            }
            $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet></Workbook>';
        return $xml;
    }

    private static function processNextJob()
    {
        $job = self::claimJob();
        if (!$job) {
            return ['ok' => true, 'claimed' => false];
        }
        $jobId = intval($job['id']);
        $result = ['ok' => false, 'done' => true, 'message' => '未知任务'];
        try {
            if ($job['job_type'] === 'full_flow') {
                $result = self::processFullFlowJob($job);
            } elseif ($job['job_type'] === 'fetch_flow') {
                $result = self::processFetchFlowJob($job);
            } elseif ($job['job_type'] === 'recalculate') {
                $result = self::processRecalculateJob($job);
            } elseif ($job['job_type'] === 'recalculate_date') {
                $result = self::processRecalculateDateJob($job);
            } elseif ($job['job_type'] === 'group_sync') {
                $result = self::processGroupSyncJob($job);
            } else {
                $result = ['ok' => false, 'done' => true, 'message' => '不支持的考勤任务类型：' . $job['job_type']];
            }
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'done' => true, 'message' => $e->getMessage()];
        }

        if (!empty($result['ok'])) {
            if (!empty($result['done'])) {
                self::updateJob($jobId, [
                    'status' => 'success',
                    'locked_at' => 0,
                    'finished_at' => time(),
                    'message' => $result['message'] ?? '完成',
                    'raw_payload' => isset($result['payload']) ? json_encode($result['payload'], JSON_UNESCAPED_UNICODE) : ($job['raw_payload'] ?? '')
                ]);
            } else {
                self::updateJob($jobId, [
                    'status' => 'pending',
                    'locked_at' => 0,
                    'next_retry' => time(),
                    'message' => $result['message'] ?? '继续处理',
                    'raw_payload' => isset($result['payload']) ? json_encode($result['payload'], JSON_UNESCAPED_UNICODE) : ($job['raw_payload'] ?? '')
                ]);
            }
        } else {
            $attempts = intval($job['attempts'] ?? 0) + 1;
            self::updateJob($jobId, [
                'status' => 'failed',
                'attempts' => $attempts,
                'locked_at' => 0,
                'next_retry' => time() + self::retryDelay($attempts),
                'message' => $result['message'] ?? '任务失败'
            ]);
        }

        $result['claimed'] = true;
        $result['job_id'] = $jobId;
        return $result;
    }

    private static function processFullFlowJob($job)
    {
        $payload = self::decodePayload($job['raw_payload'] ?? '');
        if (empty($payload['user_ids']) || !is_array($payload['user_ids'])) {
            $payload['user_ids'] = self::activeFeishuAttendanceUserIds();
            $payload['offset'] = 0;
            $payload['fetched'] = 0;
            $payload['date_cursor'] = $job['date_from'] ?: date('Y-m-d');
        }
        $userIds = $payload['user_ids'];
        $offset = max(0, intval($payload['offset'] ?? 0));
        $dateCursor = self::normalizeDate($payload['date_cursor'] ?? ($job['date_from'] ?: date('Y-m-d')), date('Y-m-d'));
        $dateTo = self::normalizeDate($job['date_to'] ?: $dateCursor, $dateCursor);
        $batchSize = max(1, min(50, Settings::getInt('attendance_full_sync_batch_size', 50)));
        if (count($userIds) === 0) {
            return ['ok' => true, 'done' => true, 'message' => '没有可同步的员工'];
        }

        if (!isset($payload['badge_backfilled_dates']) || !is_array($payload['badge_backfilled_dates'])) {
            $payload['badge_backfilled_dates'] = [];
        }
        if (!array_key_exists($dateCursor, $payload['badge_backfilled_dates'])) {
            $payload['badge_backfilled_dates'][$dateCursor] = self::backfillBadgeLogsForDate($dateCursor);
        }

        $batch = array_slice($userIds, $offset, $batchSize);
        if (count($batch) === 0) {
            if (!self::isSealedReportDate($dateCursor) || self::isManualReportSource($job['source'] ?? '')) {
                $recalculateSource = self::isManualReportSource($job['source'] ?? '') ? (string)$job['source'] : 'full_flow_done';
                self::enqueueJob('recalculate_date', $recalculateSource, $dateCursor, $dateCursor, ['offset' => 0]);
            }
            $nextDate = date('Y-m-d', strtotime($dateCursor . ' +1 day'));
            if (strtotime($nextDate) <= strtotime($dateTo)) {
                $payload['date_cursor'] = $nextDate;
                $payload['offset'] = 0;
                return ['ok' => true, 'done' => false, 'message' => '考勤流水同步切换到 ' . $nextDate, 'payload' => $payload];
            }
            Settings::set('attendance_last_full_sync_at', (string)time());
            return ['ok' => true, 'done' => true, 'message' => '飞书考勤流水全量同步完成，已提交日报重算', 'payload' => $payload];
        }

        $from = strtotime($dateCursor . ' 00:00:00');
        $to = strtotime($dateCursor . ' 23:59:59') + 1;
        $feishu = new appLinkFeishu(true);
        $resp = $feishu->queryAttendanceUserFlows($batch, $from, $to);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'message' => $resp['message'] ?? '查询飞书打卡流水失败'];
        }
        $count = 0;
        $resultRows = $resp['data']['user_flow_results'] ?? ($resp['data']['flow_records'] ?? ($resp['data']['records'] ?? []));
        if (!is_array($resultRows)) {
            $resultRows = [];
        }
        if (count($resultRows) === 0 && !empty($resp['data']['items']) && is_array($resp['data']['items'])) {
            $resultRows = $resp['data']['items'];
        }
        foreach ($resultRows as $resultRow) {
            if (!is_array($resultRow)) {
                continue;
            }
            foreach (self::flowRecordsFromQueryResult($resultRow) as $record) {
                $recordId = self::ingestFeishuFlowRecord($record, $resultRow);
                if ($recordId > 0) {
                    $count++;
                }
            }
        }
        $payload['offset'] = $offset + count($batch);
        $payload['fetched'] = intval($payload['fetched'] ?? 0) + $count;
        self::updateJob(intval($job['id']), [
            'total_count' => count($userIds),
            'processed_count' => min(count($userIds), $payload['offset']),
            'success_count' => intval($payload['fetched'])
        ]);
        return ['ok' => true, 'done' => false, 'message' => '已同步 ' . $dateCursor . ' 第 ' . ($offset + 1) . '-' . min(count($userIds), $payload['offset']) . ' 名员工流水', 'payload' => $payload];
    }

    private static function processFetchFlowJob($job)
    {
        $payload = self::decodePayload($job['raw_payload'] ?? '');
        $flowId = trim((string)($payload['flow_id'] ?? ''));
        if ($flowId === '') {
            return ['ok' => false, 'message' => '缺少飞书打卡流水ID'];
        }
        $feishu = new appLinkFeishu(true);
        $resp = $feishu->getAttendanceUserFlow($flowId);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'message' => $resp['message'] ?? '拉取飞书打卡流水失败'];
        }
        $record = $resp['data']['user_flow'] ?? ($resp['data']['record'] ?? $resp['data']);
        $record['record_id'] = $record['record_id'] ?? $flowId;
        $recordId = self::ingestFeishuFlowRecord($record, $record);
        if ($recordId > 0) {
            self::enqueueRecalculateForRecord($recordId, 'fetch_flow');
        }
        return ['ok' => true, 'done' => true, 'message' => '飞书打卡流水已入库'];
    }

    private static function processRecalculateJob($job)
    {
        $payload = self::decodePayload($job['raw_payload'] ?? '');
        $personKey = trim((string)($payload['person_key'] ?? ''));
        $date = self::normalizeDate($payload['date'] ?? '', date('Y-m-d'));
        if ($personKey === '') {
            return ['ok' => false, 'message' => '重算人员为空'];
        }
        if (self::isSealedReportDate($date) && !self::isManualReportSource($job['source'] ?? '')) {
            return ['ok' => true, 'done' => true, 'message' => '历史日报已封存，非手动范围重算已跳过：' . $date];
        }
        $notify = self::shouldNotifyForRecalculateJob($job);
        self::recalculatePersonDay($personKey, $date, null, $notify);
        $messageResult = $notify ? self::processEffectiveMessageQueue(Settings::getInt('attendance_effective_message_batch_size', 50)) : ['total' => 0, 'sent' => 0, 'failed' => 0, 'message' => '非实时增量重算，不推送有效考勤提醒'];
        Settings::set('attendance_last_incremental_at', (string)time());
        return ['ok' => true, 'done' => true, 'message' => '考勤日报已重算：' . $personKey . ' ' . $date, 'payload' => ['message' => $messageResult]];
    }

    private static function shouldNotifyForRecalculateJob($job)
    {
        $source = (string)($job['source'] ?? '');
        if ($source === 'repair_location' || $source === 'manual_panel' || strpos($source, 'schedule_') === 0) {
            return false;
        }
        return !in_array($source, ['full_flow', 'full_flow_done'], true);
    }

    private static function isSealedReportDate($date)
    {
        $date = self::normalizeDate($date, '');
        return $date !== '' && strtotime($date) < strtotime(date('Y-m-d'));
    }

    private static function isHistoricalPunchTime($timestamp)
    {
        $timestamp = intval($timestamp);
        return $timestamp > 0 && $timestamp < strtotime(date('Y-m-d'));
    }

    private static function isManualReportSource($source)
    {
        return in_array((string)$source, ['manual_panel', 'manual_report_recalculate'], true);
    }

    private static function processRecalculateDateJob($job)
    {
        $payload = self::decodePayload($job['raw_payload'] ?? '');
        $date = self::normalizeDate($job['date_from'] ?: ($payload['date'] ?? ''), date('Y-m-d'));
        if (self::isSealedReportDate($date) && !self::isManualReportSource($job['source'] ?? '')) {
            return ['ok' => true, 'done' => true, 'message' => '历史日报已封存，非手动范围重算已跳过：' . $date, 'payload' => $payload];
        }
        $offset = max(0, intval($payload['offset'] ?? 0));
        $limit = max(50, min(500, Settings::getInt('attendance_recalculate_batch_size', 200)));
        $totalCount = intval($payload['total_count'] ?? 0);
        if ($totalCount <= 0) {
            $totalCount = self::personKeyCountForDate($date);
        }
        $people = self::personKeysForDate($date, $limit, $offset);
        foreach ($people as $person) {
            self::recalculatePersonDay($person['person_key'], $date, $person['employee'], false);
        }
        $payload['offset'] = $offset + count($people);
        $payload['total_count'] = $totalCount;
        self::updateJob(intval($job['id']), [
            'total_count' => $totalCount,
            'processed_count' => $payload['offset'],
            'success_count' => intval($job['success_count'] ?? 0) + count($people)
        ]);
        if (count($people) < $limit) {
            Settings::set('attendance_last_daily_rebuild_at', (string)time());
            return ['ok' => true, 'done' => true, 'message' => $date . ' 考勤日报重算完成', 'payload' => $payload];
        }
        return ['ok' => true, 'done' => false, 'message' => $date . ' 考勤日报已重算 ' . $payload['offset'] . ' 人', 'payload' => $payload];
    }

    private static function processGroupSyncJob($job)
    {
        $feishu = new appLinkFeishu(true);
        $groups = $feishu->listAttendanceGroups();
        if (empty($groups['ok'])) {
            return ['ok' => false, 'message' => $groups['message'] ?? '拉取飞书考勤组失败'];
        }
        $count = 0;
        foreach (($groups['data']['group_list'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupId = trim((string)($group['group_id'] ?? ''));
            if ($groupId === '') {
                continue;
            }
            $detailResp = $feishu->getAttendanceGroup($groupId);
            $detail = !empty($detailResp['ok']) && is_array($detailResp['data'] ?? null) ? ($detailResp['data']['group'] ?? $detailResp['data']) : $group;
            $localGroupId = self::upsertAttendanceGroup($groupId, $detail);
            if ($localGroupId > 0) {
                $usersResp = $feishu->listAttendanceGroupUsers($groupId);
                if (!empty($usersResp['ok'])) {
                    self::replaceGroupMembers($localGroupId, $usersResp['data']['users'] ?? []);
                }
                $count++;
            }
            usleep(50000);
        }
        Settings::set('attendance_last_group_sync_at', (string)time());
        return ['ok' => true, 'done' => true, 'message' => '飞书考勤组同步完成：' . $count . ' 个'];
    }

    private static function backfillBadgeLogsForDate($date)
    {
        $date = self::normalizeDate($date, date('Y-m-d'));
        $from = strtotime($date . ' 00:00:00');
        $to = strtotime($date . ' 23:59:59');
        $rows = self::fetchRows('logs', "SELECT * FROM `logs` WHERE `time`>={$from} AND `time`<={$to} AND `passusertype`='员工' AND `action` LIKE '%成功%' AND IFNULL(`cardid`, '')<>'' ORDER BY `time` ASC");
        $count = 0;
        foreach ($rows as $row) {
            $cardId = AttendanceService::normalizeCardNumber($row['cardid'] ?? '');
            if ($cardId === '') {
                continue;
            }
            $safeCard = Database::escape($cardId);
            $employee = Database::querySingleLine('employee', "SELECT * FROM `employee` WHERE `card_id`='{$safeCard}' OR (`card_id` REGEXP '^[0-9]+$' AND LPAD(`card_id`, 10, '0')='{$safeCard}') LIMIT 1", true);
            if (!$employee) {
                continue;
            }
            $punchTime = intval($row['time'] ?? 0);
            if ($punchTime <= 0) {
                continue;
            }
            $doorName = trim((string)($row['passdoor'] ?? ''));
            $locationName = AttendanceService::externalAttendanceLocationForModule([
                'location' => $doorName,
                'door_name' => $doorName
            ]);
            $rawId = trim((string)($row['id'] ?? ''));
            $externalId = $rawId !== '' ? ('log:' . $rawId) : ('log:' . hash('sha256', implode('|', [
                $cardId,
                $punchTime,
                $doorName,
                $row['passusername'] ?? '',
                $row['action'] ?? ''
            ])));
            if (self::localBadgeSourceExists($employee, $cardId, $punchTime, $locationName, $externalId)) {
                continue;
            }
            $recordId = self::upsertSourceRecord([
                'source' => 'badge',
                'source_kind' => 'badge',
                'external_id' => $externalId,
                'event_id' => '',
                'employee_open_id' => $employee['open_id'] ?? '',
                'employee_user_id' => $employee['user_id'] ?? '',
                'employee_no' => $employee['employee_id'] ?? '',
                'employee_name' => $employee['name'] ?? ($row['passusername'] ?? ''),
                'card_id' => $cardId,
                'punch_time' => $punchTime,
                'punch_date' => date('Y-m-d', $punchTime),
                'location_name' => $locationName,
                'device_name' => $doorName,
                'raw_payload' => ['log' => self::limitedSubjectSnapshot($row)]
            ]);
            if ($recordId > 0) {
                $count++;
            }
        }
        return $count;
    }

    private static function localBadgeSourceExists($employee, $cardId, $punchTime, $locationName, $externalId)
    {
        $safeExternal = Database::escape($externalId);
        $safeCard = Database::escape($cardId);
        $safeLocation = Database::escape($locationName);
        $punchTime = intval($punchTime);
        $identity = [];
        foreach ([
            'employee_open_id' => $employee['open_id'] ?? '',
            'employee_user_id' => $employee['user_id'] ?? '',
            'employee_no' => $employee['employee_id'] ?? ''
        ] as $column => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $identity[] = "`{$column}`='" . Database::escape($value) . "'";
            }
        }
        $identitySql = count($identity) > 0 ? '(' . implode(' OR ', $identity) . ')' : '1=1';
        $row = Database::querySingleLine('attendance_source_records', "SELECT `id` FROM `attendance_source_records` WHERE `source`='badge' AND (`external_id`='{$safeExternal}' OR (`punch_time`={$punchTime} AND `card_id`='{$safeCard}' AND `location_name`='{$safeLocation}' AND {$identitySql})) LIMIT 1", true);
        return $row ? true : false;
    }

    private static function repairMissingLocationNames($limit = 500)
    {
        if (!self::enabled()) {
            return ['total' => 0, 'updated' => 0, 'message' => '考勤模块未启用'];
        }
        $limit = max(1, min(2000, intval($limit)));
        $rows = self::fetchRows('attendance_source_records', "SELECT * FROM `attendance_source_records` WHERE `source`='feishu' AND (`location_name`='' OR `location_name`='-') ORDER BY `id` ASC LIMIT {$limit}");
        $updated = 0;
        foreach ($rows as $row) {
            $raw = self::decodePayload($row['raw_payload'] ?? '');
            $candidates = [];
            if (is_array($raw)) {
                $candidates[] = $raw;
                foreach (self::flowRecordsFromQueryResult($raw) as $record) {
                    $candidates[] = $record;
                }
                foreach (self::extractRecordsFromEvent($raw) as $record) {
                    $candidates[] = $record;
                }
            }
            $location = '';
            $deviceName = '';
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $location = self::extractFeishuLocationName($candidate);
                $deviceName = self::extractFeishuDeviceName($candidate);
                if ($location === '') {
                    $location = $deviceName;
                }
                if ($location !== '') {
                    break;
                }
            }
            if ($location === '') {
                continue;
            }
            $sourceKind = self::isBadgeFlow(['location_name' => $location]) ? 'feishu_badge' : (self::isExemptFaceLocation($location) ? 'exempt_face' : (($row['source_kind'] ?? '') ?: 'face'));
            Database::update('attendance_source_records', [
                'location_name' => $location,
                'device_name' => $deviceName,
                'source_kind' => $sourceKind,
                'updated_at' => time()
            ], ['id' => intval($row['id'])]);
            self::enqueueRecalculateForRecord(intval($row['id']), 'repair_location');
            $updated++;
        }
        return ['total' => count($rows), 'updated' => $updated];
    }

    private static function upsertSourceRecord($data)
    {
        global $conn;

        $now = time();
        $record = [
            'source' => $data['source'] ?? '',
            'source_kind' => $data['source_kind'] ?? '',
            'external_id' => $data['external_id'] ?? '',
            'event_id' => $data['event_id'] ?? '',
            'employee_open_id' => $data['employee_open_id'] ?? '',
            'employee_user_id' => $data['employee_user_id'] ?? '',
            'employee_no' => $data['employee_no'] ?? '',
            'employee_name' => $data['employee_name'] ?? '',
            'card_id' => $data['card_id'] ?? '',
            'punch_time' => intval($data['punch_time'] ?? 0),
            'punch_date' => $data['punch_date'] ?? date('Y-m-d', intval($data['punch_time'] ?? time())),
            'location_name' => $data['location_name'] ?? '',
            'device_name' => $data['device_name'] ?? '',
            'raw_payload' => is_string($data['raw_payload'] ?? null) ? $data['raw_payload'] : json_encode($data['raw_payload'] ?? [], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now
        ];
        if ($record['external_id'] === '') {
            $record['external_id'] = hash('sha256', implode('|', [$record['source'], $record['employee_open_id'], $record['employee_no'], $record['punch_time'], $record['location_name']]));
        }
        if ($record['source_kind'] === '') {
            if (self::isBadgeFlow($record)) {
                $record['source_kind'] = $record['source'] === 'feishu' ? 'feishu_badge' : 'badge';
            } elseif (self::isExemptFaceLocation($record['location_name'])) {
                $record['source_kind'] = 'exempt_face';
            } else {
                $record['source_kind'] = 'face';
            }
        }
        if ($record['punch_time'] <= 0) {
            return 0;
        }

        $columns = [];
        $values = [];
        foreach ($record as $key => $value) {
            $columns[] = "`" . Database::escape($key) . "`";
            $values[] = "'" . Database::escape((string)$value) . "'";
        }
        $update = [
            "`source_kind`=VALUES(`source_kind`)",
            "`employee_open_id`=IF(VALUES(`employee_open_id`)='', `employee_open_id`, VALUES(`employee_open_id`))",
            "`employee_user_id`=IF(VALUES(`employee_user_id`)='', `employee_user_id`, VALUES(`employee_user_id`))",
            "`employee_no`=IF(VALUES(`employee_no`)='', `employee_no`, VALUES(`employee_no`))",
            "`employee_name`=IF(VALUES(`employee_name`)='', `employee_name`, VALUES(`employee_name`))",
            "`card_id`=IF(VALUES(`card_id`)='', `card_id`, VALUES(`card_id`))",
            "`location_name`=IF(VALUES(`location_name`)='', `location_name`, VALUES(`location_name`))",
            "`device_name`=IF(VALUES(`device_name`)='', `device_name`, VALUES(`device_name`))",
            "`raw_payload`=VALUES(`raw_payload`)",
            "`updated_at`=VALUES(`updated_at`)"
        ];
        $sql = "INSERT INTO `attendance_source_records` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ") ON DUPLICATE KEY UPDATE " . implode(',', $update);
        mysqli_query($conn, $sql);
        if (mysqli_error($conn)) {
            return 0;
        }
        $id = intval(mysqli_insert_id($conn));
        if ($id > 0) {
            return $id;
        }
        $source = Database::escape($record['source']);
        $externalId = Database::escape($record['external_id']);
        $row = Database::querySingleLine('attendance_source_records', "SELECT `id` FROM `attendance_source_records` WHERE `source`='{$source}' AND `external_id`='{$externalId}' LIMIT 1", true);
        return intval($row['id'] ?? 0);
    }

    private static function ingestFeishuFlowRecord($record, $rawPayload)
    {
        if (!is_array($record)) {
            return 0;
        }
        $checkTime = self::parseTimestamp($record['check_time'] ?? ($record['time'] ?? ($record['punch_time'] ?? 0)));
        if ($checkTime <= 0) {
            return 0;
        }
        $employeeType = Settings::get('feishu_employee_id_type', 'employee_no');
        $identity = self::resolveFlowIdentity($record, $employeeType);
        $userId = $identity['value'];
        $employee = $identity['employee'];
        $location = self::extractFeishuLocationName($record);
        $deviceName = self::extractFeishuDeviceName($record);
        if ($location === '') {
            $location = $deviceName;
        }
        $externalId = trim((string)($record['record_id'] ?? ($record['user_flow_id'] ?? ($record['id'] ?? ''))));
        if ($externalId === '') {
            $externalId = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE) . '|' . $checkTime);
        }
        if (self::isBadgeFlow(['location_name' => $location, 'comment' => $record['comment'] ?? ''])) {
            $sourceKind = 'feishu_badge';
        } elseif (self::isExemptFaceLocation($location)) {
            $sourceKind = 'exempt_face';
        } else {
            $sourceKind = 'face';
        }
        return self::upsertSourceRecord([
            'source' => 'feishu',
            'source_kind' => $sourceKind,
            'external_id' => $externalId,
            'event_id' => $record['event_id'] ?? '',
            'employee_open_id' => $employee['open_id'] ?? '',
            'employee_user_id' => $employee['user_id'] ?? ($identity['type'] === 'employee_id' ? $userId : trim((string)($record['employee_id'] ?? ''))),
            'employee_no' => $employee['employee_id'] ?? ($identity['type'] === 'employee_no' ? $userId : trim((string)($record['employee_no'] ?? ''))),
            'employee_name' => $employee['name'] ?? ($record['employee_name'] ?? ''),
            'card_id' => '',
            'punch_time' => $checkTime,
            'punch_date' => date('Y-m-d', $checkTime),
            'location_name' => $location,
            'device_name' => $deviceName,
            'raw_payload' => $rawPayload
        ]);
    }

    private static function resolveFlowIdentity($record, $preferredType)
    {
        $preferredType = $preferredType === 'employee_id' ? 'employee_id' : 'employee_no';
        $values = [
            'user_id' => trim((string)($record['user_id'] ?? '')),
            'employee_id' => trim((string)($record['employee_id'] ?? '')),
            'employee_no' => trim((string)($record['employee_no'] ?? ''))
        ];
        $candidates = [];
        $addCandidate = function($type, $value) use (&$candidates) {
            $value = trim((string)$value);
            if ($value === '') {
                return;
            }
            foreach ($candidates as $candidate) {
                if ($candidate['type'] === $type && $candidate['value'] === $value) {
                    return;
                }
            }
            $candidates[] = ['type' => $type, 'value' => $value];
        };
        if ($preferredType === 'employee_no') {
            $addCandidate('employee_no', $values['employee_no']);
            $addCandidate('employee_id', $values['employee_id']);
            $addCandidate('employee_no', $values['user_id']);
            $addCandidate('employee_id', $values['user_id']);
        } else {
            $addCandidate('employee_id', $values['employee_id']);
            $addCandidate('employee_no', $values['employee_no']);
            $addCandidate('employee_id', $values['user_id']);
            $addCandidate('employee_no', $values['user_id']);
        }
        foreach ($candidates as $candidate) {
            if ($candidate['value'] === '') {
                continue;
            }
            $employee = self::employeeByFeishuId($candidate['value'], $candidate['type']);
            if ($employee) {
                return [
                    'type' => $candidate['type'],
                    'value' => $candidate['value'],
                    'employee' => $employee
                ];
            }
        }
        foreach ($candidates as $candidate) {
            if ($candidate['value'] !== '') {
                return [
                    'type' => $candidate['type'],
                    'value' => $candidate['value'],
                    'employee' => null
                ];
            }
        }
        return ['type' => $preferredType, 'value' => '', 'employee' => null];
    }

    private static function flowRecordsFromQueryResult($resultRow)
    {
        if (!is_array($resultRow)) {
            return [];
        }
        $records = [];
        foreach (['records', 'user_flows', 'flow_records', 'items'] as $key) {
            if (!empty($resultRow[$key]) && is_array($resultRow[$key])) {
                foreach ($resultRow[$key] as $item) {
                    if (is_array($item)) {
                        $records[] = $item;
                    }
                }
            }
        }
        if (count($records) === 0) {
            $records[] = $resultRow;
        }

        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach (['user_id', 'employee_id', 'employee_no', 'employee_name'] as $identityKey) {
                if (empty($record[$identityKey]) && !empty($resultRow[$identityKey])) {
                    $record[$identityKey] = $resultRow[$identityKey];
                }
            }
            $out[] = $record;
        }
        return $out;
    }

    private static function extractFeishuLocationName($record)
    {
        if (!is_array($record)) {
            return '';
        }
        foreach (['location_name', 'locationName', 'check_location_name', 'checkLocationName', 'address_name', 'addressName', 'position_name', 'positionName', 'poi_name', 'poiName'] as $key) {
            if (isset($record[$key]) && is_scalar($record[$key])) {
                $value = self::cleanFeishuText($record[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        foreach (['location', 'location_info', 'locationInfo', 'check_location', 'checkLocation', 'check_location_info', 'checkLocationInfo', 'position', 'position_info', 'address', 'place'] as $key) {
            if (!empty($record[$key])) {
                $value = self::extractNameFromNestedValue($record[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function extractFeishuDeviceName($record)
    {
        if (!is_array($record)) {
            return '';
        }
        foreach (['device_name', 'deviceName', 'attendance_machine_name', 'attendanceMachineName', 'machine_name', 'machineName'] as $key) {
            if (isset($record[$key]) && is_scalar($record[$key])) {
                $value = self::cleanFeishuText($record[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        foreach (['device', 'device_info', 'deviceInfo', 'attendance_machine', 'attendanceMachine', 'machine'] as $key) {
            if (!empty($record[$key])) {
                $value = self::extractNameFromNestedValue($record[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        foreach (['device_id', 'deviceId', 'attendance_machine_id', 'attendanceMachineId', 'machine_id', 'machineId'] as $key) {
            if (isset($record[$key]) && is_scalar($record[$key])) {
                $value = self::cleanFeishuText($record[$key]);
                if ($value !== '') {
                    return '考勤机-' . $value;
                }
            }
        }
        return '';
    }

    private static function extractNameFromNestedValue($value)
    {
        if (is_scalar($value)) {
            return self::cleanFeishuText($value);
        }
        if (!is_array($value)) {
            return '';
        }
        foreach (['location_name', 'locationName', 'device_name', 'deviceName', 'name', 'title', 'address_name', 'addressName', 'address', 'display_name', 'displayName'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $text = self::cleanFeishuText($value[$key]);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $found = self::extractNameFromNestedValue($item);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    private static function cleanFeishuText($value)
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string)$value);
        return $text === '-' ? '' : $text;
    }

    private static function flowIdFromRecord($record)
    {
        if (!is_array($record)) {
            return '';
        }
        return trim((string)($record['record_id'] ?? ($record['user_flow_id'] ?? ($record['flow_id'] ?? ''))));
    }

    private static function flowLocationNeedsFetch($record)
    {
        if (!is_array($record)) {
            return false;
        }
        return self::extractFeishuLocationName($record) === '' && self::extractFeishuDeviceName($record) === '';
    }

    private static function enqueueRecalculateForRecord($recordId, $source)
    {
        $record = Database::querySingleLine('attendance_source_records', ['id' => intval($recordId)]);
        if (!$record) {
            return null;
        }
        $personKey = self::personKeyFromRecord($record);
        if ($personKey === '') {
            return null;
        }
        return self::enqueueJob('recalculate', $source, $record['punch_date'] ?? date('Y-m-d', intval($record['punch_time'] ?? time())), $record['punch_date'] ?? '', [
            'person_key' => $personKey,
            'date' => $record['punch_date'] ?? date('Y-m-d', intval($record['punch_time'] ?? time())),
            'record_id' => intval($recordId)
        ]);
    }

    private static function recalculatePersonDay($personKey, $date, $employee = null, $notify = false)
    {
        global $conn;

        $personKey = trim((string)$personKey);
        $date = self::normalizeDate($date, date('Y-m-d'));
        if ($personKey === '') {
            return false;
        }
        if (!$employee) {
            $employee = self::employeeByPersonKey($personKey);
        }

        $safePerson = Database::escape($personKey);
        $safeDate = Database::escape($date);
        mysqli_query($conn, "DELETE FROM `attendance_effective_records` WHERE `person_key`='{$safePerson}' AND `work_date`='{$safeDate}'");
        $records = self::fetchRows('attendance_source_records', "SELECT * FROM `attendance_source_records` WHERE `punch_date`='{$safeDate}' AND (CONCAT('open:', `employee_open_id`)='{$safePerson}' OR CONCAT('no:', `employee_no`)='{$safePerson}' OR CONCAT('user:', `employee_user_id`)='{$safePerson}') ORDER BY `punch_time` ASC, `id` ASC");

        $interval = max(60, min(3600, Settings::getInt('attendance_pair_interval_seconds', 300)));
        $badges = [];
        $faces = [];
        $pairs = [];
        foreach ($records as $record) {
            $sourceKind = (string)($record['source_kind'] ?? '');
            if ($sourceKind === 'badge_shadow') {
                $sourceKind = 'feishu_badge';
            }
            if ($sourceKind === 'feishu_badge' && self::hasLocalBadgeMatch($records, $record)) {
                continue;
            }
            if ($sourceKind === 'exempt_face') {
                $pairs[] = self::buildExemptPair($personKey, $date, $record);
                continue;
            }
            $kind = in_array($sourceKind, ['badge', 'feishu_badge'], true) ? 'badge' : 'face';
            if ($kind === 'badge') {
                $matchIndex = self::findMatchIndex($faces, intval($record['punch_time']), $interval);
                if ($matchIndex >= 0) {
                    $face = $faces[$matchIndex];
                    array_splice($faces, $matchIndex, 1);
                    $pairs[] = self::buildPair($personKey, $date, $record, $face);
                } else {
                    $badges[] = $record;
                }
            } else {
                $matchIndex = self::findMatchIndex($badges, intval($record['punch_time']), $interval);
                if ($matchIndex >= 0) {
                    $badge = $badges[$matchIndex];
                    array_splice($badges, $matchIndex, 1);
                    $pairs[] = self::buildPair($personKey, $date, $badge, $record);
                } else {
                    $faces[] = $record;
                }
            }
        }
        usort($pairs, function($a, $b) {
            return intval($a['effective_time']) <=> intval($b['effective_time']);
        });

        $group = self::groupForEmployee($employee, $personKey);
        $rule = self::ruleSnapshot($group, $interval);
        $invalidStats = self::invalidAttendanceStats($badges, $faces, $pairs, $group, $date, $interval);
        $sequence = 0;
        foreach ($pairs as $pair) {
            $sequence++;
            $pair['sequence_no'] = $sequence;
            $pair['group_id'] = intval($group['id'] ?? 0);
            $pair['group_name'] = $group['name'] ?? '默认考勤组';
            $pair['rule_snapshot'] = json_encode($rule, JSON_UNESCAPED_UNICODE);
            self::insertEffectivePair($pair, $notify);
        }
        self::upsertDailyReport($personKey, $date, $employee, $group, $pairs, $records, $invalidStats);
        return true;
    }

    private static function findMatchIndex($records, $targetTime, $interval)
    {
        $best = -1;
        $bestDiff = null;
        foreach ($records as $index => $record) {
            $diff = abs(intval($record['punch_time']) - intval($targetTime));
            if ($diff <= $interval && ($bestDiff === null || $diff < $bestDiff)) {
                $best = $index;
                $bestDiff = $diff;
            }
        }
        return $best;
    }

    private static function hasLocalBadgeMatch($records, $feishuBadge)
    {
        $targetTime = intval($feishuBadge['punch_time'] ?? 0);
        $targetLocation = self::normalizeBadgeLocationText($feishuBadge['location_name'] ?? '');
        foreach ($records as $record) {
            if (($record['source_kind'] ?? '') !== 'badge') {
                continue;
            }
            if (abs(intval($record['punch_time'] ?? 0) - $targetTime) > 2) {
                continue;
            }
            $recordLocation = self::normalizeBadgeLocationText($record['location_name'] ?? '');
            if ($targetLocation === '' || $recordLocation === '' || $targetLocation === $recordLocation) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeBadgeLocationText($value)
    {
        $value = trim((string)$value);
        if (strpos($value, '工牌-') === 0) {
            $value = substr($value, strlen('工牌-'));
        }
        return $value;
    }

    private static function invalidAttendanceStats($badges, $faces, $pairs, $group, $date, $interval)
    {
        $first = count($pairs) > 0 ? intval($pairs[0]['effective_time']) : 0;
        $last = count($pairs) > 0 ? intval($pairs[count($pairs) - 1]['effective_time']) : 0;
        $startText = $group['start_time'] ?? Settings::get('attendance_default_start_time', '09:30');
        $endText = $group['end_time'] ?? Settings::get('attendance_default_end_time', '18:30');
        $scheduledStart = strtotime($date . ' ' . $startText . ':00');
        $scheduledEnd = strtotime($date . ' ' . $endText . ':00');
        $grace = max(0, min(3600, Settings::getInt('attendance_late_grace_seconds', 60)));
        $now = time();

        $invalidRows = [];
        foreach ($badges as $record) {
            $time = intval($record['punch_time'] ?? 0);
            if ($time > 0) {
                $invalidRows[] = ['time' => $time, 'kind' => 'badge'];
            }
        }
        foreach ($faces as $record) {
            $time = intval($record['punch_time'] ?? 0);
            if ($time > 0) {
                $invalidRows[] = ['time' => $time, 'kind' => 'face'];
            }
        }
        usort($invalidRows, function($a, $b) {
            return intval($a['time']) <=> intval($b['time']);
        });

        $invalidBadgeCount = count($badges);
        $invalidFaceCount = count($faces);
        $invalidTotal = $invalidBadgeCount + $invalidFaceCount;
        $workStartValid = $first > 0 ? 1 : 0;
        $workEndValid = ($scheduledEnd > 0 && $last >= $scheduledEnd) ? 1 : 0;
        $isLate = ($first > 0 && $scheduledStart > 0 && $first > $scheduledStart + $grace) ? 1 : 0;
        $isEarlyLeave = ($first > 0 && $scheduledEnd > 0 && $now > $scheduledEnd && $last < $scheduledEnd) ? 1 : 0;
        $isFullAbsent = ($first <= 0 && $invalidTotal === 0) ? 1 : 0;

        $invalidLateCount = 0;
        $invalidEarlyLeaveCount = 0;
        $invalidLateFaceCount = 0;
        $invalidLateBadgeCount = 0;
        $invalidEarlyLeaveFaceCount = 0;
        $invalidEarlyLeaveBadgeCount = 0;
        $startValidCutoff = $scheduledStart > 0 ? $scheduledStart : 0;
        foreach ($invalidRows as $row) {
            $time = intval($row['time'] ?? 0);
            $kind = (string)($row['kind'] ?? '');
            if ($isLate && $startValidCutoff > 0 && $time <= $startValidCutoff) {
                $invalidLateCount++;
                if ($kind === 'face') {
                    $invalidLateFaceCount++;
                } elseif ($kind === 'badge') {
                    $invalidLateBadgeCount++;
                }
            }
            if ($isEarlyLeave && $scheduledEnd > 0 && $time >= $scheduledEnd) {
                $invalidEarlyLeaveCount++;
                if ($kind === 'face') {
                    $invalidEarlyLeaveFaceCount++;
                } elseif ($kind === 'badge') {
                    $invalidEarlyLeaveBadgeCount++;
                }
            }
        }

        return [
            'invalid_badge_count' => $invalidBadgeCount,
            'invalid_face_count' => $invalidFaceCount,
            'invalid_total' => $invalidTotal,
            'work_start_valid' => $workStartValid,
            'work_end_valid' => $workEndValid,
            'is_late' => $isLate,
            'is_early_leave' => $isEarlyLeave,
            'is_full_absent' => $isFullAbsent,
            'invalid_late_count' => $invalidLateCount,
            'invalid_early_leave_count' => $invalidEarlyLeaveCount,
            'invalid_late_face_count' => $invalidLateFaceCount,
            'invalid_late_badge_count' => $invalidLateBadgeCount,
            'invalid_early_leave_face_count' => $invalidEarlyLeaveFaceCount,
            'invalid_early_leave_badge_count' => $invalidEarlyLeaveBadgeCount,
            'invalid_late_related' => ($isLate && $invalidLateCount > 0) ? 1 : 0,
            'invalid_early_leave_related' => ($isEarlyLeave && $invalidEarlyLeaveCount > 0) ? 1 : 0
        ];
    }

    private static function buildPair($personKey, $date, $badge, $face)
    {
        $badgeTime = intval($badge['punch_time']);
        $faceTime = intval($face['punch_time']);
        $effectiveTime = max($badgeTime, $faceTime);
        $pairHash = hash('sha256', implode('|', [$personKey, $date, $badge['id'], $face['id']]));
        $locationName = self::effectiveLocationName($badge, $face);
        $deviceName = self::effectiveDeviceName($badge, $face);
        return [
            'pair_hash' => $pairHash,
            'person_key' => $personKey,
            'employee_open_id' => $badge['employee_open_id'] ?: ($face['employee_open_id'] ?? ''),
            'employee_user_id' => $badge['employee_user_id'] ?: ($face['employee_user_id'] ?? ''),
            'employee_no' => $badge['employee_no'] ?: ($face['employee_no'] ?? ''),
            'employee_name' => $badge['employee_name'] ?: ($face['employee_name'] ?? ''),
            'work_date' => $date,
            'effective_time' => $effectiveTime,
            'badge_record_id' => intval($badge['id']),
            'face_record_id' => intval($face['id']),
            'badge_time' => $badgeTime,
            'face_time' => $faceTime,
            'interval_seconds' => abs($badgeTime - $faceTime),
            'status' => 'normal',
            'location_name' => $locationName,
            'device_name' => $deviceName
        ];
    }

    private static function buildExemptPair($personKey, $date, $record)
    {
        $time = intval($record['punch_time']);
        $pairHash = hash('sha256', implode('|', [$personKey, $date, 'exempt', $record['id']]));
        return [
            'pair_hash' => $pairHash,
            'person_key' => $personKey,
            'employee_open_id' => $record['employee_open_id'] ?? '',
            'employee_user_id' => $record['employee_user_id'] ?? '',
            'employee_no' => $record['employee_no'] ?? '',
            'employee_name' => $record['employee_name'] ?? '',
            'work_date' => $date,
            'effective_time' => $time,
            'badge_record_id' => 0,
            'face_record_id' => intval($record['id']),
            'badge_time' => 0,
            'face_time' => $time,
            'interval_seconds' => 0,
            'status' => 'exempt',
            'location_name' => $record['location_name'] ?? '',
            'device_name' => $record['device_name'] ?? ''
        ];
    }

    private static function insertEffectivePair($pair, $notify = false)
    {
        $now = time();
        $data = array_merge($pair, [
            'created_at' => $now,
            'updated_at' => $now
        ]);
        $result = Database::insert('attendance_effective_records', $data);
        if ($result === true && $notify) {
            self::enqueueEffectiveMessage($pair);
        }
        if ($result === true) {
            self::markIncompleteMessageSkippedBySourceIds([
                intval($pair['badge_record_id'] ?? 0),
                intval($pair['face_record_id'] ?? 0)
            ], '已完成双验证考勤');
        }
    }

    private static function effectiveLocationName($badge, $face)
    {
        $faceLocation = trim((string)($face['location_name'] ?? ''));
        if ($faceLocation !== '') {
            return $faceLocation;
        }
        return trim((string)($badge['location_name'] ?? ''));
    }

    private static function effectiveDeviceName($badge, $face)
    {
        $faceDevice = trim((string)($face['device_name'] ?? ''));
        if ($faceDevice !== '') {
            return $faceDevice;
        }
        return trim((string)($badge['device_name'] ?? ''));
    }

    private static function enqueueEffectiveMessage($pair)
    {
        global $conn;

        if (!Settings::getBool('attendance_effective_message_enabled')) {
            return false;
        }
        $openId = trim((string)($pair['employee_open_id'] ?? ''));
        if ($openId === '') {
            return false;
        }
        $now = time();
        $data = [
            'pair_hash' => $pair['pair_hash'] ?? '',
            'person_key' => $pair['person_key'] ?? '',
            'employee_open_id' => $openId,
            'employee_user_id' => $pair['employee_user_id'] ?? '',
            'employee_no' => $pair['employee_no'] ?? '',
            'employee_name' => $pair['employee_name'] ?? '',
            'work_date' => $pair['work_date'] ?? date('Y-m-d', intval($pair['effective_time'] ?? time())),
            'effective_time' => intval($pair['effective_time'] ?? 0),
            'badge_time' => intval($pair['badge_time'] ?? 0),
            'face_time' => intval($pair['face_time'] ?? 0),
            'interval_seconds' => intval($pair['interval_seconds'] ?? 0),
            'status_text' => $pair['status'] ?? '',
            'group_name' => $pair['group_name'] ?? '',
            'location_name' => $pair['location_name'] ?? '',
            'device_name' => $pair['device_name'] ?? '',
            'message_status' => 'pending',
            'message_next_retry' => 0,
            'raw_payload' => json_encode($pair, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now
        ];
        if ($data['pair_hash'] === '' || $data['effective_time'] <= 0) {
            return false;
        }

        $columns = [];
        $values = [];
        foreach ($data as $key => $value) {
            $columns[] = "`" . Database::escape($key) . "`";
            $values[] = "'" . Database::escape((string)$value) . "'";
        }
        $sql = "INSERT IGNORE INTO `attendance_effective_message_queue` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
        mysqli_query($conn, $sql);
        return mysqli_affected_rows($conn) > 0;
    }

    private static function processEffectiveMessageQueue($limit = 50)
    {
        if (!Settings::getBool('attendance_effective_message_enabled')) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'message' => '有效考勤提醒未启用'];
        }
        $limit = max(1, min(200, intval($limit)));
        $now = time();
        $rows = self::fetchRows('attendance_effective_message_queue', "SELECT * FROM `attendance_effective_message_queue` WHERE `message_status` IN ('pending','failed') AND `message_next_retry`<={$now} ORDER BY `id` ASC LIMIT {$limit}");
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $feishu = new appLinkFeishu(true);
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if (self::isHistoricalPunchTime($row['effective_time'] ?? 0)) {
                self::markEffectiveMessageSkipped([$row], '历史有效考勤提醒已跳过');
                continue;
            }
            if (trim((string)($row['employee_open_id'] ?? '')) === '') {
                self::markEffectiveMessageFailed([$row], '缺少飞书 open_id');
                $failed++;
                continue;
            }
            $uuid = substr('eff_' . ($row['pair_hash'] ?? ''), 0, 50);
            $resp = $feishu->sendInteractiveMessage($row['employee_open_id'], self::buildEffectiveMessageCard($row), $uuid);
            if (!empty($resp['ok'])) {
                self::markEffectiveMessageSent([$row], json_encode($resp['data'], JSON_UNESCAPED_UNICODE));
                $sent++;
            } else {
                self::markEffectiveMessageFailed([$row], $resp['message'] ?? '飞书消息发送失败');
                $failed++;
            }
            usleep(50000);
        }
        return ['total' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    private static function markEffectiveMessageSent($rows, $response)
    {
        self::markEffectiveMessages($rows, 'sent', 0, $response);
    }

    private static function markEffectiveMessageFailed($rows, $response)
    {
        self::markEffectiveMessages($rows, 'failed', null, $response);
    }

    private static function markEffectiveMessageSkipped($rows, $response)
    {
        self::markEffectiveMessages($rows, 'skipped', 0, $response);
    }

    private static function markEffectiveMessages($rows, $status, $nextRetry, $response)
    {
        global $conn;

        if (count($rows) === 0) {
            return;
        }
        $now = time();
        $response = Database::escape(substr((string)$response, 0, 60000));
        $status = Database::escape($status);
        if ($status === 'failed') {
            foreach ($rows as $row) {
                $attempts = intval($row['message_attempts'] ?? 0) + 1;
                $retryAt = $now + self::retryDelay($attempts);
                $id = intval($row['id']);
                mysqli_query($conn, "UPDATE `attendance_effective_message_queue` SET `message_status`='{$status}', `message_attempts`={$attempts}, `message_next_retry`={$retryAt}, `message_response`='{$response}', `updated_at`={$now} WHERE `id`={$id}");
            }
            return;
        }
        $ids = array_map(function($row) { return intval($row['id']); }, $rows);
        mysqli_query($conn, "UPDATE `attendance_effective_message_queue` SET `message_status`='{$status}', `message_next_retry`=0, `message_response`='{$response}', `updated_at`={$now} WHERE `id` IN (" . implode(',', $ids) . ")");
    }

    private static function processIncompleteMessageQueue($limit = 50)
    {
        if (!Settings::getBool('attendance_incomplete_message_enabled')) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'message' => '双验证补刷提醒未启用'];
        }
        $limit = max(1, min(200, intval($limit)));
        $interval = max(60, min(3600, Settings::getInt('attendance_pair_interval_seconds', 300)));
        $lead = max(30, min($interval - 1, Settings::getInt('attendance_incomplete_message_lead_seconds', 120)));
        $now = time();
        $todayStart = strtotime(date('Y-m-d'));
        $duePunchTime = $now - max(0, $interval - $lead);
        $rows = self::fetchRows('attendance_source_records', "SELECT * FROM `attendance_source_records` WHERE `warning_status` IN ('pending','failed') AND `warning_next_retry`<={$now} AND `punch_time`>={$todayStart} AND `punch_time`<={$duePunchTime} AND `source_kind` IN ('badge','feishu_badge','face') ORDER BY `punch_time` ASC, `id` ASC LIMIT {$limit}");
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $feishu = new appLinkFeishu(true);
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $punchTime = intval($row['punch_time'] ?? 0);
            if (self::isHistoricalPunchTime($punchTime)) {
                self::markIncompleteMessages([$row], 'skipped', 0, '历史补刷提醒已跳过');
                continue;
            }
            if ($punchTime + $interval < $now) {
                self::markIncompleteMessages([$row], 'skipped', 0, '双验证补刷窗口已结束');
                continue;
            }
            if (self::sourceRecordAlreadyEffective($row) || self::sourceRecordHasCounterpart($row, $interval)) {
                self::markIncompleteMessages([$row], 'skipped', 0, '已完成双验证考勤');
                continue;
            }
            $messageRow = self::buildIncompleteMessageRow($row, $interval, $lead);
            if (!$messageRow) {
                self::markIncompleteMessages([$row], 'skipped', 0, '非上班/下班边界时段，不发送补刷提醒');
                continue;
            }
            if (trim((string)($messageRow['employee_open_id'] ?? '')) === '') {
                self::markIncompleteMessages([$row], 'failed', null, '缺少飞书 open_id');
                $failed++;
                continue;
            }
            $uuid = substr('inc_' . intval($row['id']) . '_' . intval($row['punch_time']), 0, 50);
            $resp = $feishu->sendInteractiveMessage($messageRow['employee_open_id'], self::buildIncompleteMessageCard($messageRow), $uuid);
            if (!empty($resp['ok'])) {
                self::markIncompleteMessages([$row], 'sent', 0, json_encode($resp['data'], JSON_UNESCAPED_UNICODE));
                $sent++;
            } else {
                self::markIncompleteMessages([$row], 'failed', null, $resp['message'] ?? '飞书补刷提醒发送失败');
                $failed++;
            }
            usleep(50000);
        }
        return ['total' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    private static function buildIncompleteMessageRow($row, $interval, $lead)
    {
        $personKey = self::personKeyFromRecord($row);
        $employee = self::employeeByPersonKey($personKey);
        $group = self::groupForEmployee($employee, $personKey);
        $punchTime = intval($row['punch_time'] ?? 0);
        $date = date('Y-m-d', $punchTime > 0 ? $punchTime : time());
        $startText = $group['start_time'] ?? Settings::get('attendance_default_start_time', '09:30');
        $endText = $group['end_time'] ?? Settings::get('attendance_default_end_time', '18:30');
        $scheduledStart = strtotime($date . ' ' . $startText . ':00');
        $scheduledEnd = strtotime($date . ' ' . $endText . ':00');
        $phase = '';
        if ($scheduledStart > 0 && $punchTime <= $scheduledStart) {
            $phase = '上班';
        } elseif ($scheduledEnd > 0 && $punchTime >= $scheduledEnd) {
            $phase = '下班';
        } else {
            return null;
        }
        $kind = self::sourceKindRole($row);
        $doneMethod = $kind === 'badge' ? '刷卡' : '刷脸';
        $missingMethod = $kind === 'badge' ? '刷脸' : '刷卡';
        $deadline = $punchTime + intval($interval);
        $openId = trim((string)($row['employee_open_id'] ?? ''));
        $userId = trim((string)($row['employee_user_id'] ?? ''));
        $employeeNo = trim((string)($row['employee_no'] ?? ''));
        $employeeName = trim((string)($row['employee_name'] ?? ''));
        return [
            'source_record_id' => intval($row['id'] ?? 0),
            'source_kind' => $row['source_kind'] ?? '',
            'person_key' => $personKey,
            'employee_open_id' => $openId !== '' ? $openId : ($employee['open_id'] ?? ''),
            'employee_user_id' => $userId !== '' ? $userId : ($employee['user_id'] ?? ''),
            'employee_no' => $employeeNo !== '' ? $employeeNo : ($employee['employee_id'] ?? ''),
            'employee_name' => $employeeName !== '' ? $employeeName : ($employee['name'] ?? ''),
            'work_date' => $date,
            'effective_time' => $punchTime,
            'punch_time' => $punchTime,
            'deadline_time' => $deadline,
            'lead_seconds' => intval($lead),
            'interval_seconds' => intval($interval),
            'phase' => $phase,
            'done_method' => $doneMethod,
            'missing_method' => $missingMethod,
            'group_name' => $group['name'] ?? Settings::get('attendance_default_group_name', '默认考勤组'),
            'location_name' => $row['location_name'] ?? '',
            'device_name' => $row['device_name'] ?? ''
        ];
    }

    private static function sourceKindRole($row)
    {
        $kind = (string)($row['source_kind'] ?? '');
        return in_array($kind, ['badge', 'feishu_badge'], true) ? 'badge' : 'face';
    }

    private static function sourceRecordAlreadyEffective($row)
    {
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        $found = Database::querySingleLine('attendance_effective_records', "SELECT `id` FROM `attendance_effective_records` WHERE `badge_record_id`={$id} OR `face_record_id`={$id} LIMIT 1", true);
        return $found ? true : false;
    }

    private static function sourceRecordHasCounterpart($row, $interval)
    {
        $personKey = self::personKeyFromRecord($row);
        if ($personKey === '') {
            return false;
        }
        $time = intval($row['punch_time'] ?? 0);
        if ($time <= 0) {
            return false;
        }
        $from = $time - intval($interval);
        $to = $time + intval($interval);
        $safePerson = Database::escape($personKey);
        $kindSql = self::sourceKindRole($row) === 'badge' ? "`source_kind`='face'" : "`source_kind` IN ('badge','feishu_badge')";
        $sql = "SELECT `id` FROM `attendance_source_records` WHERE `id`<>" . intval($row['id'] ?? 0) . " AND `punch_time` BETWEEN {$from} AND {$to} AND {$kindSql} AND (CONCAT('open:', `employee_open_id`)='{$safePerson}' OR CONCAT('no:', `employee_no`)='{$safePerson}' OR CONCAT('user:', `employee_user_id`)='{$safePerson}') LIMIT 1";
        $found = Database::querySingleLine('attendance_source_records', $sql, true);
        return $found ? true : false;
    }

    private static function markIncompleteMessageSkippedBySourceIds($ids, $response)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (count($ids) === 0) {
            return;
        }
        global $conn;
        $now = time();
        $response = Database::escape(substr((string)$response, 0, 60000));
        mysqli_query($conn, "UPDATE `attendance_source_records` SET `warning_status`='skipped', `warning_next_retry`=0, `warning_response`='{$response}', `updated_at`={$now} WHERE `id` IN (" . implode(',', $ids) . ") AND `warning_status` IN ('pending','failed')");
    }

    private static function markIncompleteMessages($rows, $status, $nextRetry, $response)
    {
        global $conn;

        if (count($rows) === 0) {
            return;
        }
        $now = time();
        $response = Database::escape(substr((string)$response, 0, 60000));
        $status = Database::escape($status);
        if ($status === 'failed') {
            foreach ($rows as $row) {
                $attempts = intval($row['warning_attempts'] ?? 0) + 1;
                $retryAt = $now + self::retryDelay($attempts);
                $id = intval($row['id']);
                mysqli_query($conn, "UPDATE `attendance_source_records` SET `warning_status`='{$status}', `warning_attempts`={$attempts}, `warning_next_retry`={$retryAt}, `warning_response`='{$response}', `updated_at`={$now} WHERE `id`={$id}");
            }
            return;
        }
        $sentAtSql = $status === 'sent' ? ", `warning_sent_at`={$now}" : '';
        $ids = array_map(function($row) { return intval($row['id']); }, $rows);
        mysqli_query($conn, "UPDATE `attendance_source_records` SET `warning_status`='{$status}', `warning_next_retry`=0{$sentAtSql}, `warning_response`='{$response}', `updated_at`={$now} WHERE `id` IN (" . implode(',', $ids) . ")");
    }

    private static function buildIncompleteMessageCard($row)
    {
        $titleText = Settings::get('attendance_incomplete_message_template', '双验证考勤提醒');
        if ($titleText === '') {
            $titleText = '双验证考勤提醒';
        }
        $template = Settings::get('attendance_incomplete_message_card_template', '');
        if (trim($template) === '') {
            $template = "**考勤提醒** 还未完成双验证考勤\n**已完成** {done_method}\n**待完成** {missing_method}\n**截止时间** {deadline_datetime}\n请在截止前及时补{missing_method}。";
        }
        $rendered = self::renderEffectiveMessageTemplate($template, $row);
        $customCard = json_decode($rendered, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($customCard)) {
            $customCard['config'] = $customCard['config'] ?? ['wide_screen_mode' => true];
            $customCard['header'] = [
                'template' => 'red',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => self::renderEffectiveMessageTemplate($titleText, $row)
                ]
            ];
            return $customCard;
        }
        return [
            'config' => ['wide_screen_mode' => true],
            'header' => [
                'template' => 'red',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => self::renderEffectiveMessageTemplate($titleText, $row)
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

    private static function buildEffectiveMessageCard($row)
    {
        $titleText = Settings::get('attendance_effective_message_template', '有效考勤');
        if ($titleText === '') {
            $titleText = '有效考勤';
        }
        $template = Settings::get('attendance_effective_message_card_template', '');
        if (trim($template) === '') {
            $template = "**考勤结果** 有效考勤\n**考勤时间** {datetime}\n**考勤点位** {location}\n**工牌时间** {badge_datetime}\n**人脸时间** {face_datetime}";
        }
        $rendered = self::renderEffectiveMessageTemplate($template, $row);
        $customCard = json_decode($rendered, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($customCard)) {
            $customCard['config'] = $customCard['config'] ?? ['wide_screen_mode' => true];
            $customCard['header'] = [
                'template' => $customCard['header']['template'] ?? 'green',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => self::renderEffectiveMessageTemplate($titleText, $row)
                ]
            ];
            return $customCard;
        }
        return [
            'config' => ['wide_screen_mode' => true],
            'header' => [
                'template' => 'green',
                'title' => [
                    'tag' => 'plain_text',
                    'content' => self::renderEffectiveMessageTemplate($titleText, $row)
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

    private static function renderEffectiveMessageTemplate($template, $row)
    {
        $effectiveTime = intval($row['effective_time'] ?? time());
        $badgeTime = intval($row['badge_time'] ?? 0);
        $faceTime = intval($row['face_time'] ?? 0);
        $punchTime = intval($row['punch_time'] ?? $effectiveTime);
        $deadlineTime = intval($row['deadline_time'] ?? 0);
        $location = trim((string)($row['location_name'] ?? ''));
        if ($location === '') {
            $location = trim((string)($row['device_name'] ?? ''));
        }
        if ($location === '') {
            $location = '-';
        }
        $remainingSeconds = $deadlineTime > 0 ? max(0, $deadlineTime - time()) : 0;
        $replacements = [
            '{time}' => date('H:i', $effectiveTime),
            '{date}' => date('Y年m月d日', $effectiveTime),
            '{datetime}' => date('Y-m-d H:i:s', $effectiveTime),
            '{work_date}' => $row['work_date'] ?? date('Y-m-d', $effectiveTime),
            '{name}' => (string)($row['employee_name'] ?? ''),
            '{employee_no}' => (string)($row['employee_no'] ?? ''),
            '{location}' => $location,
            '{device}' => (string)($row['device_name'] ?? ''),
            '{group}' => (string)($row['group_name'] ?? ''),
            '{status}' => self::statusText($row['status_text'] ?? ''),
            '{badge_time}' => $badgeTime > 0 ? date('H:i:s', $badgeTime) : '-',
            '{badge_datetime}' => $badgeTime > 0 ? date('Y-m-d H:i:s', $badgeTime) : '-',
            '{face_time}' => $faceTime > 0 ? date('H:i:s', $faceTime) : '-',
            '{face_datetime}' => $faceTime > 0 ? date('Y-m-d H:i:s', $faceTime) : '-',
            '{interval_seconds}' => (string)intval($row['interval_seconds'] ?? 0),
            '{punch_time}' => $punchTime > 0 ? date('H:i:s', $punchTime) : '-',
            '{punch_datetime}' => $punchTime > 0 ? date('Y-m-d H:i:s', $punchTime) : '-',
            '{deadline_time}' => $deadlineTime > 0 ? date('H:i:s', $deadlineTime) : '-',
            '{deadline_datetime}' => $deadlineTime > 0 ? date('Y-m-d H:i:s', $deadlineTime) : '-',
            '{remaining_seconds}' => (string)$remainingSeconds,
            '{remaining_minutes}' => (string)intval(ceil($remainingSeconds / 60)),
            '{phase}' => (string)($row['phase'] ?? ''),
            '{done_method}' => (string)($row['done_method'] ?? ''),
            '{missing_method}' => (string)($row['missing_method'] ?? ''),
            '{pair_hash}' => (string)($row['pair_hash'] ?? '')
        ];
        return strtr((string)$template, $replacements);
    }

    private static function upsertDailyReport($personKey, $date, $employee, $group, $pairs, $records, $invalidStats = [])
    {
        global $conn;

        $first = count($pairs) > 0 ? intval($pairs[0]['effective_time']) : 0;
        $last = count($pairs) > 0 ? intval($pairs[count($pairs) - 1]['effective_time']) : 0;
        $startText = $group['start_time'] ?? Settings::get('attendance_default_start_time', '09:30');
        $endText = $group['end_time'] ?? Settings::get('attendance_default_end_time', '18:30');
        $scheduledStart = strtotime($date . ' ' . $startText . ':00');
        $scheduledEnd = strtotime($date . ' ' . $endText . ':00');
        $grace = max(0, min(3600, Settings::getInt('attendance_late_grace_seconds', 60)));
        $now = time();
        $lateMinutes = 0;
        $workStartValid = intval($invalidStats['work_start_valid'] ?? ($first > 0 ? 1 : 0));
        $workEndValid = intval($invalidStats['work_end_valid'] ?? (($scheduledEnd > 0 && $last >= $scheduledEnd) ? 1 : 0));
        $isLate = intval($invalidStats['is_late'] ?? (($first > 0 && $scheduledStart > 0 && $first > $scheduledStart + $grace) ? 1 : 0));
        $isEarlyLeave = intval($invalidStats['is_early_leave'] ?? (($first > 0 && $scheduledEnd > 0 && $now > $scheduledEnd && $last < $scheduledEnd) ? 1 : 0));
        $isFullAbsent = intval($invalidStats['is_full_absent'] ?? (($first <= 0 && intval($invalidStats['invalid_total'] ?? 0) === 0) ? 1 : 0));
        if ($isLate) {
            $lateMinutes = intval(ceil(($first - $scheduledStart) / 60));
        }
        $status = self::legacyDailyStatus($first, $isLate, $isEarlyLeave);
        $statusFields = self::dailyStatusFields($workStartValid, $workEndValid, $isLate, $isEarlyLeave, $isFullAbsent, $invalidStats);
        $statusFlags = implode(',', array_keys($statusFields));
        $statusText = implode('、', array_values($statusFields));
        $oaStatus = Settings::getBool('attendance_oa_push_enabled') ? 'pending' : 'skipped';
        $reportHash = hash('sha256', $personKey . '|' . $date);
        $data = [
            'report_hash' => $reportHash,
            'person_key' => $personKey,
            'employee_open_id' => $employee['open_id'] ?? self::personKeyPart($personKey, 'open'),
            'employee_user_id' => $employee['user_id'] ?? self::personKeyPart($personKey, 'user'),
            'employee_no' => $employee['employee_id'] ?? self::personKeyPart($personKey, 'no'),
            'employee_name' => $employee['name'] ?? self::nameFromRecords($records),
            'work_date' => $date,
            'group_id' => intval($group['id'] ?? 0),
            'group_name' => $group['name'] ?? '默认考勤组',
            'scheduled_start' => $scheduledStart > 0 ? $scheduledStart : 0,
            'scheduled_end' => $scheduledEnd > 0 ? $scheduledEnd : 0,
            'first_effective_at' => $first,
            'last_effective_at' => $last,
            'effective_count' => count($pairs),
            'late_minutes' => $lateMinutes,
            'status' => $status,
            'status_flags' => $statusFlags,
            'status_text' => $statusText,
            'work_start_valid' => $workStartValid,
            'work_end_valid' => $workEndValid,
            'is_late' => $isLate,
            'is_early_leave' => $isEarlyLeave,
            'is_full_absent' => $isFullAbsent,
            'invalid_face_count' => intval($invalidStats['invalid_face_count'] ?? 0),
            'invalid_badge_count' => intval($invalidStats['invalid_badge_count'] ?? 0),
            'invalid_total' => intval($invalidStats['invalid_total'] ?? 0),
            'invalid_late_count' => intval($invalidStats['invalid_late_count'] ?? 0),
            'invalid_early_leave_count' => intval($invalidStats['invalid_early_leave_count'] ?? 0),
            'invalid_late_face_count' => intval($invalidStats['invalid_late_face_count'] ?? 0),
            'invalid_late_badge_count' => intval($invalidStats['invalid_late_badge_count'] ?? 0),
            'invalid_early_leave_face_count' => intval($invalidStats['invalid_early_leave_face_count'] ?? 0),
            'invalid_early_leave_badge_count' => intval($invalidStats['invalid_early_leave_badge_count'] ?? 0),
            'invalid_late_related' => intval($invalidStats['invalid_late_related'] ?? 0),
            'invalid_early_leave_related' => intval($invalidStats['invalid_early_leave_related'] ?? 0),
            'source_updated_at' => $now,
            'calculated_at' => $now,
            'raw_trace' => json_encode([
                'source_record_ids' => array_map(function($row) { return intval($row['id']); }, $records),
                'feishu_badge_compare' => self::feishuBadgeComparison($records),
                'invalid_stats' => $invalidStats,
                'pairs' => array_map(function($pair) {
                    return [
                        'badge_record_id' => intval($pair['badge_record_id']),
                        'face_record_id' => intval($pair['face_record_id']),
                        'badge_time' => intval($pair['badge_time']),
                        'face_time' => intval($pair['face_time']),
                        'effective_time' => intval($pair['effective_time']),
                        'location_name' => $pair['location_name'] ?? '',
                        'device_name' => $pair['device_name'] ?? ''
                    ];
                }, $pairs)
            ], JSON_UNESCAPED_UNICODE),
            'oa_status' => $oaStatus,
            'updated_at' => $now,
            'created_at' => $now
        ];

        $columns = [];
        $values = [];
        foreach ($data as $key => $value) {
            $columns[] = "`" . Database::escape($key) . "`";
            $values[] = "'" . Database::escape((string)$value) . "'";
        }
        $update = [];
        foreach ($data as $key => $value) {
            if ($key === 'created_at') {
                continue;
            }
            $update[] = "`" . Database::escape($key) . "`=VALUES(`" . Database::escape($key) . "`)";
        }
        $sql = "INSERT INTO `attendance_daily_reports` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ") ON DUPLICATE KEY UPDATE " . implode(',', $update);
        mysqli_query($conn, $sql);
    }

    private static function legacyDailyStatus($first, $isLate, $isEarlyLeave)
    {
        if (intval($first) <= 0) {
            return 'absent';
        }
        if (intval($isLate) === 1) {
            return 'late';
        }
        if (intval($isEarlyLeave) === 1) {
            return 'early_leave';
        }
        return 'normal';
    }

    private static function dailyStatusFields($workStartValid, $workEndValid, $isLate, $isEarlyLeave, $isFullAbsent, $invalidStats)
    {
        $fields = [];
        if (intval($isFullAbsent) === 1) {
            $fields['full_absent'] = '完全缺勤';
        }
        if (intval($workStartValid) === 1) {
            $fields['work_start_valid'] = '上班有效';
        }
        if (intval($workEndValid) === 1) {
            $fields['work_end_valid'] = '下班有效';
        }
        if (intval($isLate) === 1) {
            $fields['late'] = '迟到';
        }
        if (intval($isEarlyLeave) === 1) {
            $fields['early_leave'] = '早退';
        }
        $invalidFace = intval($invalidStats['invalid_face_count'] ?? 0);
        $invalidBadge = intval($invalidStats['invalid_badge_count'] ?? 0);
        if ($invalidFace > 0) {
            $fields['only_face'] = '只刷脸 ' . $invalidFace . ' 次';
        }
        if ($invalidBadge > 0) {
            $fields['only_badge'] = '只刷卡 ' . $invalidBadge . ' 次';
        }
        if (intval($invalidStats['invalid_late_related'] ?? 0) === 1) {
            $fields['invalid_late_related'] = '上班时间及以前单边验证导致迟到（刷脸' . intval($invalidStats['invalid_late_face_count'] ?? 0) . ' / 刷卡' . intval($invalidStats['invalid_late_badge_count'] ?? 0) . '）';
        }
        if (intval($invalidStats['invalid_early_leave_related'] ?? 0) === 1) {
            $fields['invalid_early_leave_related'] = '下班单边验证导致早退（刷脸' . intval($invalidStats['invalid_early_leave_face_count'] ?? 0) . ' / 刷卡' . intval($invalidStats['invalid_early_leave_badge_count'] ?? 0) . '）';
        }
        if (count($fields) === 0) {
            $fields['normal'] = '正常';
        }
        return $fields;
    }

    private static function feishuBadgeComparison($records)
    {
        $result = [
            'feishu_badge_total' => 0,
            'matched_local_total' => 0,
            'missing_local_total' => 0,
            'missing_local_record_ids' => []
        ];
        foreach ($records as $record) {
            $sourceKind = (string)($record['source_kind'] ?? '');
            if (!in_array($sourceKind, ['feishu_badge', 'badge_shadow'], true)) {
                continue;
            }
            $result['feishu_badge_total']++;
            if (self::hasLocalBadgeMatch($records, $record)) {
                $result['matched_local_total']++;
            } else {
                $result['missing_local_total']++;
                $result['missing_local_record_ids'][] = intval($record['id'] ?? 0);
            }
        }
        return $result;
    }

    private static function enqueueFetchFlow($flowId, $eventId, $source)
    {
        return self::enqueueJob('fetch_flow', $source, '', '', [
            'flow_id' => $flowId,
            'event_id' => $eventId
        ]);
    }

    private static function enqueueJob($jobType, $source, $dateFrom, $dateTo, $payload)
    {
        global $conn;

        $now = time();
        $data = [
            'job_type' => $jobType,
            'source' => $source,
            'status' => 'pending',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'next_retry' => 0,
            'created_at' => $now,
            'updated_at' => $now
        ];
        $ok = Database::insert('attendance_sync_jobs', $data);
        if ($ok === true) {
            return ['ok' => true, 'job_id' => intval(mysqli_insert_id($conn)), 'message' => '已提交考勤任务'];
        }
        return ['ok' => false, 'message' => (string)$ok];
    }

    private static function claimJob()
    {
        global $conn;

        $now = time();
        $stale = $now - 900;
        $rs = Database::query('attendance_sync_jobs', "SELECT * FROM `attendance_sync_jobs` WHERE (`status` IN ('pending','failed') AND `next_retry`<={$now}) OR (`status`='running' AND `locked_at`<{$stale}) ORDER BY `id` ASC LIMIT 1", '', true);
        if (!($rs instanceof \mysqli_result)) {
            return null;
        }
        $job = mysqli_fetch_assoc($rs);
        mysqli_free_result($rs);
        if (!$job) {
            return null;
        }
        $id = intval($job['id']);
        $ok = mysqli_query($conn, "UPDATE `attendance_sync_jobs` SET `status`='running', `locked_at`={$now}, `started_at`=IF(`started_at`=0, {$now}, `started_at`), `updated_at`={$now} WHERE `id`={$id}");
        return $ok ? $job : null;
    }

    private static function updateJob($jobId, $data)
    {
        $data['updated_at'] = time();
        return Database::update('attendance_sync_jobs', $data, ['id' => intval($jobId)]);
    }

    private static function hasDueJobs()
    {
        $now = time();
        $row = Database::querySingleLine('attendance_sync_jobs', "SELECT `id` FROM `attendance_sync_jobs` WHERE (`status` IN ('pending','failed') AND `next_retry`<={$now}) OR (`status`='running' AND `locked_at`<" . ($now - 900) . ") LIMIT 1", true);
        return $row ? true : false;
    }

    private static function processOaQueue($limit)
    {
        if (!Settings::getBool('attendance_oa_push_enabled')) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'message' => '考勤 AMT 推送未启用'];
        }
        $limit = max(1, min(500, intval($limit)));
        $now = time();
        $rows = self::fetchRows('attendance_daily_reports', "SELECT * FROM `attendance_daily_reports` WHERE `oa_status`<>'sent' AND `oa_status`<>'skipped' AND `oa_next_retry`<={$now} ORDER BY `id` ASC LIMIT {$limit}");
        if (count($rows) === 0) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0];
        }

        $baseUrl = rtrim(Settings::get('oa_base_url', ''), '/');
        $appId = Settings::get('oa_app_id', '');
        $appSecret = Settings::get('oa_app_secret', '');
        if ($baseUrl === '' || $appId === '' || $appSecret === '') {
            self::markOaFailed($rows, 'OA配置不完整');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }

        $token = self::getOaToken($baseUrl, $appId, $appSecret);
        if ($token === '') {
            self::markOaFailed($rows, '无法获取OA token');
            return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
        }
        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'employeeId' => $row['employee_open_id'],
                'employeeNo' => $row['employee_no'],
                'date' => $row['work_date'],
                'firstEffectiveAt' => intval($row['first_effective_at']) > 0 ? date('Y-m-d H:i:s', intval($row['first_effective_at'])) : '',
                'lastEffectiveAt' => intval($row['last_effective_at']) > 0 ? date('Y-m-d H:i:s', intval($row['last_effective_at'])) : '',
                'effectiveCount' => intval($row['effective_count']),
                'status' => $row['status'],
                'statusText' => self::reportStatusText($row),
                'statusFlags' => $row['status_flags'] ?? '',
                'workStartValid' => intval($row['work_start_valid'] ?? 0),
                'workEndValid' => intval($row['work_end_valid'] ?? 0),
                'isLate' => intval($row['is_late'] ?? 0),
                'isEarlyLeave' => intval($row['is_early_leave'] ?? 0),
                'isFullAbsent' => intval($row['is_full_absent'] ?? 0),
                'invalidFaceCount' => intval($row['invalid_face_count'] ?? 0),
                'invalidBadgeCount' => intval($row['invalid_badge_count'] ?? 0),
                'invalidLateRelated' => intval($row['invalid_late_related'] ?? 0),
                'invalidEarlyLeaveRelated' => intval($row['invalid_early_leave_related'] ?? 0),
                'lateMinutes' => intval($row['late_minutes'])
            ];
        }
        $url = $baseUrl . Settings::get('attendance_oa_push_path', '/open/user/v1/attendanceEffective/upload');
        $resp = self::postJson($url, ['token' => $token, 'data' => $records], [], 20);
        $businessCode = intval($resp['response']['code'] ?? 0);
        if (($resp['status_code'] ?? 0) == 200 && ($businessCode === 0 || $businessCode === 200)) {
            self::markOaSent($rows, json_encode($resp['response'], JSON_UNESCAPED_UNICODE));
            return ['total' => count($rows), 'sent' => count($rows), 'failed' => 0];
        }
        self::markOaFailed($rows, json_encode($resp, JSON_UNESCAPED_UNICODE));
        return ['total' => count($rows), 'sent' => 0, 'failed' => count($rows)];
    }

    private static function markOaSent($rows, $response)
    {
        $ids = array_map(function($row) { return intval($row['id']); }, $rows);
        if (count($ids) === 0) {
            return;
        }
        $response = Database::escape(substr($response, 0, 60000));
        Database::update('attendance_daily_reports', [], "UPDATE `attendance_daily_reports` SET `oa_status`='sent', `oa_next_retry`=0, `oa_response`='{$response}', `updated_at`=" . time() . " WHERE `id` IN (" . implode(',', $ids) . ")", '', true);
    }

    private static function markOaFailed($rows, $response)
    {
        $response = Database::escape(substr($response, 0, 60000));
        $now = time();
        foreach ($rows as $row) {
            $attempts = intval($row['oa_attempts'] ?? 0) + 1;
            $retry = $now + self::retryDelay($attempts);
            $id = intval($row['id']);
            Database::update('attendance_daily_reports', [], "UPDATE `attendance_daily_reports` SET `oa_status`='failed', `oa_attempts`={$attempts}, `oa_next_retry`={$retry}, `oa_response`='{$response}', `updated_at`={$now} WHERE `id`={$id}", '', true);
        }
    }

    private static function getOaToken($baseUrl, $appId, $appSecret)
    {
        $url = $baseUrl . Settings::get('oa_auth_path', '/open/auth/token') . '?appId=' . rawurlencode($appId) . '&appSecret=' . rawurlencode($appSecret);
        $resp = self::postJson($url, null, [], 10);
        if (($resp['status_code'] ?? 0) == 200 && intval($resp['response']['code'] ?? 0) === 200 && !empty($resp['response']['data']['accessToken'])) {
            return $resp['response']['data']['accessToken'];
        }
        return '';
    }

    private static function postJson($url, $body = null, $headers = [], $timeout = 10)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json; charset=utf-8'], $headers));
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

    private static function upsertAttendanceGroup($groupId, $detail)
    {
        global $conn;

        $now = time();
        $times = self::extractGroupTimes($detail);
        $data = [
            'group_key' => 'feishu:' . $groupId,
            'name' => $detail['group_name'] ?? ($detail['name'] ?? $groupId),
            'source' => 'feishu',
            'feishu_group_id' => $groupId,
            'start_time' => $times['start'],
            'end_time' => $times['end'],
            'enabled' => 1,
            'auto_sync' => 1,
            'member_count' => 0,
            'raw_payload' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now
        ];
        $columns = [];
        $values = [];
        foreach ($data as $key => $value) {
            $columns[] = "`" . Database::escape($key) . "`";
            $values[] = "'" . Database::escape((string)$value) . "'";
        }
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key !== 'created_at') {
                $updates[] = "`" . Database::escape($key) . "`=VALUES(`" . Database::escape($key) . "`)";
            }
        }
        mysqli_query($conn, "INSERT INTO `attendance_groups` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ") ON DUPLICATE KEY UPDATE " . implode(',', $updates));
        $id = intval(mysqli_insert_id($conn));
        if ($id > 0) {
            return $id;
        }
        $key = Database::escape('feishu:' . $groupId);
        $row = Database::querySingleLine('attendance_groups', "SELECT `id` FROM `attendance_groups` WHERE `group_key`='{$key}' LIMIT 1", true);
        return intval($row['id'] ?? 0);
    }

    private static function replaceGroupMembers($groupId, $users)
    {
        $groupId = intval($groupId);
        Database::delete('attendance_group_members', ['group_id' => $groupId]);
        $count = 0;
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $userId = trim((string)($user['user_id'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $employee = self::employeeByFeishuId($userId, Settings::get('feishu_employee_id_type', 'employee_no'));
            Database::insert('attendance_group_members', [
                'group_id' => $groupId,
                'employee_open_id' => $employee['open_id'] ?? '',
                'employee_user_id' => $employee['user_id'] ?? ($userId),
                'employee_no' => $employee['employee_id'] ?? ($userId),
                'employee_name' => $employee['name'] ?? '',
                'created_at' => time()
            ]);
            $count++;
        }
        Database::update('attendance_groups', ['member_count' => $count, 'updated_at' => time()], ['id' => $groupId]);
    }

    private static function groupForEmployee($employee, $personKey)
    {
        $where = '';
        if ($employee) {
            $openId = Database::escape($employee['open_id'] ?? '');
            $userId = Database::escape($employee['user_id'] ?? '');
            $employeeNo = Database::escape($employee['employee_id'] ?? '');
            $where = "(`m`.`employee_open_id`<>'' AND `m`.`employee_open_id`='{$openId}') OR (`m`.`employee_user_id`<>'' AND `m`.`employee_user_id`='{$userId}') OR (`m`.`employee_no`<>'' AND `m`.`employee_no`='{$employeeNo}')";
        } else {
            $partOpen = Database::escape(self::personKeyPart($personKey, 'open'));
            $partNo = Database::escape(self::personKeyPart($personKey, 'no'));
            $partUser = Database::escape(self::personKeyPart($personKey, 'user'));
            $where = "(`m`.`employee_open_id`<>'' AND `m`.`employee_open_id`='{$partOpen}') OR (`m`.`employee_user_id`<>'' AND `m`.`employee_user_id`='{$partUser}') OR (`m`.`employee_no`<>'' AND `m`.`employee_no`='{$partNo}')";
        }
        $row = Database::querySingleLine('attendance_groups', "SELECT `g`.* FROM `attendance_groups` g INNER JOIN `attendance_group_members` m ON m.`group_id`=g.`id` WHERE g.`enabled`=1 AND ({$where}) ORDER BY g.`id` ASC LIMIT 1", true);
        if ($row) {
            return $row;
        }
        return [
            'id' => 0,
            'name' => Settings::get('attendance_default_group_name', '默认考勤组'),
            'start_time' => Settings::get('attendance_default_start_time', '09:30'),
            'end_time' => Settings::get('attendance_default_end_time', '18:30')
        ];
    }

    private static function activeFeishuAttendanceUserIds()
    {
        $employeeType = Settings::get('feishu_employee_id_type', 'employee_no');
        $column = $employeeType === 'employee_id' ? 'user_id' : 'employee_id';
        $rows = self::fetchRows('employee', "SELECT `{$column}` FROM `employee` WHERE `status`='true' AND `{$column}`<>'' ORDER BY `id` ASC");
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row[$column];
        }
        return array_values(array_unique($ids));
    }

    private static function activeEmployees($limit, $offset)
    {
        $limit = max(1, intval($limit));
        $offset = max(0, intval($offset));
        return self::fetchRows('employee', "SELECT * FROM `employee` WHERE `status`='true' ORDER BY `id` ASC LIMIT {$offset}, {$limit}");
    }

    private static function personKeysForDate($date, $limit, $offset)
    {
        $date = self::normalizeDate($date, date('Y-m-d'));
        $safeDate = Database::escape($date);
        $limit = max(1, intval($limit));
        $offset = max(0, intval($offset));
        $sql = self::personKeysForDateSql($safeDate) . " ORDER BY `person_key` ASC LIMIT {$offset}, {$limit}";
        $rows = self::fetchRows('attendance_daily_reports', $sql);
        $people = [];
        foreach ($rows as $row) {
            $personKey = trim((string)($row['person_key'] ?? ''));
            if ($personKey === '') {
                continue;
            }
            $people[] = [
                'person_key' => $personKey,
                'employee' => self::employeeByPersonKey($personKey)
            ];
        }
        return $people;
    }

    private static function personKeyCountForDate($date)
    {
        $date = self::normalizeDate($date, date('Y-m-d'));
        $safeDate = Database::escape($date);
        $row = Database::querySingleLine('attendance_daily_reports', "SELECT COUNT(*) AS `total` FROM (" . self::personKeysForDateSql($safeDate) . ") t", true);
        return intval($row['total'] ?? 0);
    }

    private static function personKeysForDateSql($safeDate)
    {
        return "SELECT `person_key` FROM (
            SELECT CASE WHEN `open_id`<>'' THEN CONCAT('open:', `open_id`) WHEN `employee_id`<>'' THEN CONCAT('no:', `employee_id`) WHEN `user_id`<>'' THEN CONCAT('user:', `user_id`) ELSE '' END AS `person_key`
            FROM `employee` WHERE `status`='true' AND (`open_id`<>'' OR `employee_id`<>'' OR `user_id`<>'')
            UNION
            SELECT CASE WHEN `employee_open_id`<>'' THEN CONCAT('open:', `employee_open_id`) WHEN `employee_no`<>'' THEN CONCAT('no:', `employee_no`) WHEN `employee_user_id`<>'' THEN CONCAT('user:', `employee_user_id`) ELSE '' END AS `person_key`
            FROM `attendance_source_records` WHERE `punch_date`='{$safeDate}' AND (`employee_open_id`<>'' OR `employee_no`<>'' OR `employee_user_id`<>'')
            UNION
            SELECT `person_key` FROM `attendance_daily_reports` WHERE `work_date`='{$safeDate}' AND `person_key`<>''
        ) p WHERE `person_key`<>''";
    }

    private static function employeeByFeishuId($userId, $employeeType)
    {
        $userId = trim((string)$userId);
        if ($userId === '') {
            return null;
        }
        if ($employeeType === 'employee_id') {
            $employee = Database::querySingleLine('employee', ['user_id' => $userId]);
            if ($employee) {
                return $employee;
            }
        }
        $employee = Database::querySingleLine('employee', ['employee_id' => $userId]);
        if ($employee) {
            return $employee;
        }
        return Database::querySingleLine('employee', ['user_id' => $userId]);
    }

    private static function employeeByPersonKey($personKey)
    {
        if (strpos($personKey, 'open:') === 0) {
            return Database::querySingleLine('employee', ['open_id' => substr($personKey, 5)]);
        }
        if (strpos($personKey, 'no:') === 0) {
            return Database::querySingleLine('employee', ['employee_id' => substr($personKey, 3)]);
        }
        if (strpos($personKey, 'user:') === 0) {
            return Database::querySingleLine('employee', ['user_id' => substr($personKey, 5)]);
        }
        return null;
    }

    private static function personKeyFromEmployee($employee)
    {
        if (!empty($employee['open_id'])) {
            return 'open:' . $employee['open_id'];
        }
        if (!empty($employee['employee_id'])) {
            return 'no:' . $employee['employee_id'];
        }
        if (!empty($employee['user_id'])) {
            return 'user:' . $employee['user_id'];
        }
        return '';
    }

    private static function personKeyFromRecord($record)
    {
        if (!empty($record['employee_open_id'])) {
            return 'open:' . $record['employee_open_id'];
        }
        if (!empty($record['employee_no'])) {
            return 'no:' . $record['employee_no'];
        }
        if (!empty($record['employee_user_id'])) {
            return 'user:' . $record['employee_user_id'];
        }
        return '';
    }

    private static function personKeyFromReport($report)
    {
        if (!empty($report['person_key'])) {
            return $report['person_key'];
        }
        if (!empty($report['employee_open_id'])) {
            return 'open:' . $report['employee_open_id'];
        }
        if (!empty($report['employee_no'])) {
            return 'no:' . $report['employee_no'];
        }
        return 'user:' . ($report['employee_user_id'] ?? '');
    }

    private static function personKeyPart($personKey, $type)
    {
        $prefix = $type . ':';
        return strpos($personKey, $prefix) === 0 ? substr($personKey, strlen($prefix)) : '';
    }

    private static function reportWhere($filters)
    {
        $where = [];
        $dateFrom = self::normalizeDate($filters['date_from'] ?? '', date('Y-m-d'));
        $dateTo = self::normalizeDate($filters['date_to'] ?? '', $dateFrom);
        if (strtotime($dateFrom) > strtotime($dateTo)) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }
        $where[] = "`work_date`>='" . Database::escape($dateFrom) . "'";
        $where[] = "`work_date`<='" . Database::escape($dateTo) . "'";
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            if ($status === 'early_leave') {
                $where[] = "`is_early_leave`=1";
            } elseif ($status === 'full_absent') {
                $where[] = "`is_full_absent`=1";
            } elseif ($status === 'work_start_valid') {
                $where[] = "`work_start_valid`=1";
            } elseif ($status === 'work_end_valid') {
                $where[] = "`work_end_valid`=1";
            } elseif ($status === 'only_face') {
                $where[] = "`invalid_face_count`>0";
            } elseif ($status === 'only_badge') {
                $where[] = "`invalid_badge_count`>0";
            } elseif ($status === 'invalid_late_related') {
                $where[] = "`invalid_late_related`=1";
            } elseif ($status === 'invalid_early_leave_related') {
                $where[] = "`invalid_early_leave_related`=1";
            } elseif ($status === 'late') {
                $where[] = "`is_late`=1";
            } elseif ($status === 'missing_checkout') {
                $where[] = "(`status`='missing_checkout' OR `is_early_leave`=1)";
            } else {
                $where[] = "`status`='" . Database::escape($status) . "'";
            }
        }
        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $safe = Database::escape($keyword);
            $like = "'%{$safe}%'";
            $where[] = "(`employee_name` LIKE {$like} OR `employee_no` LIKE {$like} OR `group_name` LIKE {$like})";
        }
        return 'WHERE ' . implode(' AND ', $where);
    }

    private static function extractRecordsFromEvent($payload)
    {
        $records = [];
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : $payload;
        if (self::recordHasEnoughFields($event)) {
            $records[] = $event;
        }
        foreach (['user_flow', 'user_flow_record', 'record', 'attendance_record', 'data'] as $key) {
            if (isset($event[$key]) && is_array($event[$key])) {
                $records[] = $event[$key];
            }
        }
        foreach (['user_flows', 'records', 'attendance_records', 'items'] as $key) {
            if (isset($event[$key]) && is_array($event[$key])) {
                foreach ($event[$key] as $item) {
                    if (is_array($item)) {
                        $records[] = $item;
                    }
                }
            }
        }
        if (count($records) === 0) {
            foreach (['record_id', 'user_flow_id', 'flow_id'] as $key) {
                if (!empty($event[$key])) {
                    $records[] = [
                        'record_id' => $event[$key],
                        'user_id' => $event['user_id'] ?? '',
                        'employee_id' => $event['employee_id'] ?? '',
                        'employee_no' => $event['employee_no'] ?? '',
                        'check_time' => $event['check_time'] ?? ''
                    ];
                }
            }
        }
        return $records;
    }

    private static function recordHasEnoughFields($record)
    {
        if (!is_array($record)) {
            return false;
        }
        $hasUser = !empty($record['user_id']) || !empty($record['employee_id']) || !empty($record['employee_no']);
        $hasTime = !empty($record['check_time']) || !empty($record['time']) || !empty($record['punch_time']);
        return $hasUser && $hasTime;
    }

    private static function isBadgeFlow($record)
    {
        $location = trim((string)($record['location_name'] ?? ($record['location'] ?? '')));
        return strpos($location, '工牌-') === 0;
    }

    private static function isExemptFaceLocation($location)
    {
        $location = trim((string)$location);
        if ($location === '') {
            return false;
        }
        foreach (self::exemptLocationPrefixes() as $prefix) {
            if ($prefix !== '' && strpos($location, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function exemptLocationPrefixes()
    {
        $value = Settings::get('attendance_exempt_location_prefixes', '');
        $items = preg_split('/[,\n;]+/', (string)$value);
        $prefixes = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') {
                $prefixes[] = $item;
            }
        }
        return array_values(array_unique($prefixes));
    }

    private static function extractGroupTimes($detail)
    {
        $start = self::firstTimeText($detail, ['free_start_time', 'on_time', 'start_time', 'begin_time']);
        $end = self::firstTimeText($detail, ['free_end_time', 'off_time', 'end_time', 'finish_time']);
        return [
            'start' => $start ?: Settings::get('attendance_default_start_time', '09:30'),
            'end' => $end ?: Settings::get('attendance_default_end_time', '18:30')
        ];
    }

    private static function firstTimeText($value, $keys)
    {
        if (!is_array($value)) {
            return '';
        }
        foreach ($keys as $key) {
            if (isset($value[$key]) && preg_match('/^\d{1,2}:\d{2}$/', (string)$value[$key])) {
                return self::normalizeTime((string)$value[$key]);
            }
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $found = self::firstTimeText($item, $keys);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    private static function ruleSnapshot($group, $interval)
    {
        return [
            'pair_interval_seconds' => $interval,
            'late_grace_seconds' => Settings::getInt('attendance_late_grace_seconds', 60),
            'group_id' => intval($group['id'] ?? 0),
            'group_name' => $group['name'] ?? '',
            'start_time' => $group['start_time'] ?? '',
            'end_time' => $group['end_time'] ?? '',
            'effective_time_rule' => 'max(badge_time, face_time)'
        ];
    }

    private static function configuredTimes($value)
    {
        $items = preg_split('/[,\n;\s]+/', (string)$value);
        $times = [];
        foreach ($items as $item) {
            $item = trim($item);
            if (preg_match('/^\d{2}:\d{2}$/', $item)) {
                $times[] = $item;
            }
        }
        return array_values(array_unique($times));
    }

    private static function normalizeDate($date, $default)
    {
        $date = trim((string)$date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            return $default;
        }
        return date('Y-m-d', strtotime($date));
    }

    private static function normalizeTime($time)
    {
        $time = trim((string)$time);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return '';
        }
        $hour = intval($m[1]);
        $minute = intval($m[2]);
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return '';
        }
        return str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$minute, 2, '0', STR_PAD_LEFT);
    }

    private static function parseTimestamp($value)
    {
        if (is_numeric($value)) {
            $time = intval($value);
            if ($time > 100000000000) {
                $time = intval($time / 1000);
            }
            return $time;
        }
        $time = strtotime((string)$value);
        return $time === false ? 0 : intval($time);
    }

    private static function fetchRows($table, $sql)
    {
        $rs = Database::query($table, $sql, '', true);
        $rows = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $rows[] = $row;
            }
            mysqli_free_result($rs);
        }
        return $rows;
    }

    private static function decodePayload($payload)
    {
        $decoded = json_decode((string)$payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function retryDelay($attempts)
    {
        $base = max(1, Settings::getInt('queue_retry_base_seconds', 60));
        $max = max($base, Settings::getInt('queue_retry_max_seconds', 3600));
        return min($max, intval($base * pow(2, min(6, max(0, intval($attempts) - 1)))));
    }

    private static function limitedSubjectSnapshot($value)
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $out[$key] = $item;
            }
        }
        return $out;
    }

    private static function nameFromRecords($records)
    {
        foreach ($records as $record) {
            $name = trim((string)($record['employee_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return '';
    }

    private static function exportFields()
    {
        $configured = Settings::get('attendance_export_fields', 'work_date,employee_name,employee_no,group_name,scheduled_start,scheduled_end,first_effective_at,last_effective_at,effective_count,status_text,work_start_valid,work_end_valid,is_late,is_early_leave,is_full_absent,invalid_face_count,invalid_badge_count,invalid_late_face_count,invalid_late_badge_count,invalid_early_leave_face_count,invalid_early_leave_badge_count,late_minutes');
        $fields = array_filter(array_map('trim', explode(',', $configured)));
        return count($fields) > 0 ? $fields : ['work_date','employee_name','employee_no','status'];
    }

    private static function exportValue($row, $field)
    {
        if (in_array($field, ['scheduled_start','scheduled_end','first_effective_at','last_effective_at','updated_at'], true)) {
            $time = intval($row[$field] ?? 0);
            return $time > 0 ? date('Y-m-d H:i:s', $time) : '-';
        }
        if ($field === 'status') {
            return self::statusText($row['status'] ?? '');
        }
        if ($field === 'status_text') {
            return self::reportStatusText($row);
        }
        if (in_array($field, ['work_start_valid','work_end_valid','is_late','is_early_leave','is_full_absent','invalid_late_related','invalid_early_leave_related'], true)) {
            return intval($row[$field] ?? 0) === 1 ? '是' : '否';
        }
        if ($field === 'trace') {
            return self::traceSummary($row);
        }
        return trim((string)($row[$field] ?? '')) !== '' ? (string)$row[$field] : '-';
    }

    public static function reportStatusText($row)
    {
        $text = trim((string)($row['status_text'] ?? ''));
        if ($text !== '') {
            return $text;
        }
        return self::statusText($row['status'] ?? '');
    }

    public static function statusText($status)
    {
        $map = [
            'normal' => '正常',
            'exempt' => '免工牌有效',
            'late' => '迟到',
            'absent' => '缺勤',
            'early_leave' => '早退',
            'missing_checkout' => '缺少下班有效考勤',
            'full_absent' => '完全缺勤',
            'work_start_valid' => '上班有效',
            'work_end_valid' => '下班有效',
            'only_face' => '只刷脸',
            'only_badge' => '只刷卡',
            'invalid_late_related' => '上班时间及以前单边验证导致迟到',
            'invalid_early_leave_related' => '下班单边验证导致早退'
        ];
        return $map[$status] ?? ($status ?: '-');
    }

    private static function traceSummary($row)
    {
        $parts = [];
        $parts[] = '有效 ' . intval($row['effective_count'] ?? 0) . ' 次';
        $invalidFace = intval($row['invalid_face_count'] ?? 0);
        $invalidBadge = intval($row['invalid_badge_count'] ?? 0);
        if ($invalidFace > 0 || $invalidBadge > 0) {
            $parts[] = '只刷脸 ' . $invalidFace . ' 次';
            $parts[] = '只刷卡 ' . $invalidBadge . ' 次';
        }
        if (intval($row['invalid_late_related'] ?? 0) === 1) {
            $parts[] = '上班时间及以前单边验证 ' . intval($row['invalid_late_count'] ?? 0) . ' 次（刷脸' . intval($row['invalid_late_face_count'] ?? 0) . ' / 刷卡' . intval($row['invalid_late_badge_count'] ?? 0) . '）';
        }
        if (intval($row['invalid_early_leave_related'] ?? 0) === 1) {
            $parts[] = '下班后单边验证 ' . intval($row['invalid_early_leave_count'] ?? 0) . ' 次（刷脸' . intval($row['invalid_early_leave_face_count'] ?? 0) . ' / 刷卡' . intval($row['invalid_early_leave_badge_count'] ?? 0) . '）';
        }
        return implode('；', $parts);
    }

    private static function xml($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function acquireWorkerLock()
    {
        $path = self::workerLockPath();
        $fp = @fopen($path, 'c');
        if (!$fp) {
            return ['ok' => false, 'path' => $path];
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return ['ok' => false, 'path' => $path];
        }
        ftruncate($fp, 0);
        fwrite($fp, json_encode(['pid' => getmypid(), 'started_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
        fflush($fp);
        return ['ok' => true, 'path' => $path, 'handle' => $fp];
    }

    private static function releaseWorkerLock($lock)
    {
        if (is_array($lock) && !empty($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }

    private static function workerLocked()
    {
        $path = self::workerLockPath();
        $fp = @fopen($path, 'c');
        if (!$fp) {
            return false;
        }
        $locked = !flock($fp, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $locked;
    }

    private static function workerLockPath()
    {
        $root = rtrim(defined('ROOT') ? ROOT : dirname(__DIR__), '/\\');
        $dir = $root . '/tmp';
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = sys_get_temp_dir();
        }
        return rtrim($dir, '/\\') . '/doorlock_attendance_worker.lock';
    }

    private static function functionAvailable($name)
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if (!$disabled) {
            return true;
        }
        return !in_array($name, array_map('trim', explode(',', $disabled)), true);
    }

    private static function phpBinary()
    {
        global $_config;

        $configured = trim((string)($_config['phpCliBinary'] ?? ($_config['workerPhpBinary'] ?? '')));
        if ($configured !== '') {
            return $configured;
        }
        $binary = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $base = strtolower(basename($binary));
        if ((strpos($base, 'php-fpm') !== false || strpos($base, 'php-cgi') !== false) && defined('PHP_BINDIR')) {
            return 'php';
        }
        return $binary;
    }
}
