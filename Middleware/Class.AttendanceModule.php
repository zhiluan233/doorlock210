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
            if (self::recordHasEnoughFields($record)) {
                $recordId = self::ingestFeishuFlowRecord($record, $payload);
                if ($recordId > 0) {
                    self::enqueueRecalculateForRecord($recordId, 'feishu_event');
                }
                if ($firstOpenId === '') {
                    $firstOpenId = $record['employee_open_id'] ?? '';
                }
                continue;
            }
            $flowId = trim((string)($record['record_id'] ?? ($record['user_flow_id'] ?? '')));
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
            $days = max(1, min(31, Settings::getInt('attendance_full_sync_window_days', 2)));
            $dateTo = $today;
            $dateFrom = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' day'));
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
            'worker' => self::ensureWorkerRunning(),
            'oa' => self::processOaQueue(Settings::getInt('attendance_oa_batch_size', 100))
        ];
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
        $row = Database::querySingleLine('attendance_daily_reports', "SELECT COUNT(*) AS `total`, SUM(CASE WHEN `status`='normal' THEN 1 ELSE 0 END) AS `normal_total`, SUM(CASE WHEN `status`='late' THEN 1 ELSE 0 END) AS `late_total`, SUM(CASE WHEN `status`='absent' THEN 1 ELSE 0 END) AS `absent_total`, SUM(`effective_count`) AS `effective_total` FROM `attendance_daily_reports` {$where}", true);
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
            'late_minutes' => '迟到分钟',
            'updated_at' => '更新时间',
            'trace' => '溯源'
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
            self::enqueueJob('recalculate_date', 'full_flow_done', $dateCursor, $dateCursor, ['offset' => 0]);
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
        self::recalculatePersonDay($personKey, $date);
        Settings::set('attendance_last_incremental_at', (string)time());
        return ['ok' => true, 'done' => true, 'message' => '考勤日报已重算：' . $personKey . ' ' . $date];
    }

    private static function processRecalculateDateJob($job)
    {
        $payload = self::decodePayload($job['raw_payload'] ?? '');
        $date = self::normalizeDate($job['date_from'] ?: ($payload['date'] ?? ''), date('Y-m-d'));
        $offset = max(0, intval($payload['offset'] ?? 0));
        $limit = max(50, min(500, Settings::getInt('attendance_recalculate_batch_size', 200)));
        $employees = self::activeEmployees($limit, $offset);
        foreach ($employees as $employee) {
            self::recalculatePersonDay(self::personKeyFromEmployee($employee), $date, $employee);
        }
        $payload['offset'] = $offset + count($employees);
        self::updateJob(intval($job['id']), [
            'processed_count' => $payload['offset'],
            'success_count' => intval($job['success_count'] ?? 0) + count($employees)
        ]);
        if (count($employees) < $limit) {
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
                $record['source_kind'] = $record['source'] === 'feishu' ? 'badge_shadow' : 'badge';
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
        $location = trim((string)($record['location_name'] ?? ($record['location'] ?? ($record['device_name'] ?? ''))));
        $externalId = trim((string)($record['record_id'] ?? ($record['user_flow_id'] ?? ($record['id'] ?? ''))));
        if ($externalId === '') {
            $externalId = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE) . '|' . $checkTime);
        }
        if (self::isBadgeFlow(['location_name' => $location, 'comment' => $record['comment'] ?? ''])) {
            $sourceKind = 'badge_shadow';
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
            'device_name' => $record['device_id'] ?? ($record['device_name'] ?? ''),
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
        if ($preferredType === 'employee_no') {
            $candidates[] = ['type' => 'employee_no', 'value' => $values['employee_no']];
            $candidates[] = ['type' => 'employee_no', 'value' => $values['user_id']];
            $candidates[] = ['type' => 'employee_id', 'value' => $values['employee_id']];
        } else {
            $candidates[] = ['type' => 'employee_id', 'value' => $values['employee_id']];
            $candidates[] = ['type' => 'employee_id', 'value' => $values['user_id']];
            $candidates[] = ['type' => 'employee_no', 'value' => $values['employee_no']];
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

    private static function recalculatePersonDay($personKey, $date, $employee = null)
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
            if (($record['source_kind'] ?? '') === 'badge_shadow') {
                continue;
            }
            if (($record['source_kind'] ?? '') === 'exempt_face') {
                $pairs[] = self::buildExemptPair($personKey, $date, $record);
                continue;
            }
            $kind = $record['source_kind'] === 'badge' ? 'badge' : 'face';
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
        $sequence = 0;
        foreach ($pairs as $pair) {
            $sequence++;
            $pair['sequence_no'] = $sequence;
            $pair['group_id'] = intval($group['id'] ?? 0);
            $pair['group_name'] = $group['name'] ?? '默认考勤组';
            $pair['rule_snapshot'] = json_encode($rule, JSON_UNESCAPED_UNICODE);
            self::insertEffectivePair($pair);
        }
        self::upsertDailyReport($personKey, $date, $employee, $group, $pairs, $records);
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

    private static function buildPair($personKey, $date, $badge, $face)
    {
        $badgeTime = intval($badge['punch_time']);
        $faceTime = intval($face['punch_time']);
        $effectiveTime = max($badgeTime, $faceTime);
        $pairHash = hash('sha256', implode('|', [$personKey, $date, $badge['id'], $face['id']]));
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
            'status' => 'normal'
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
            'status' => 'exempt'
        ];
    }

    private static function insertEffectivePair($pair)
    {
        $now = time();
        $data = array_merge($pair, [
            'created_at' => $now,
            'updated_at' => $now
        ]);
        Database::insert('attendance_effective_records', $data);
    }

    private static function upsertDailyReport($personKey, $date, $employee, $group, $pairs, $records)
    {
        global $conn;

        $first = count($pairs) > 0 ? intval($pairs[0]['effective_time']) : 0;
        $last = count($pairs) > 0 ? intval($pairs[count($pairs) - 1]['effective_time']) : 0;
        $startText = $group['start_time'] ?? Settings::get('attendance_default_start_time', '09:30');
        $endText = $group['end_time'] ?? Settings::get('attendance_default_end_time', '18:30');
        $scheduledStart = strtotime($date . ' ' . $startText . ':00');
        $scheduledEnd = strtotime($date . ' ' . $endText . ':00');
        $grace = max(0, min(3600, Settings::getInt('attendance_late_grace_seconds', 60)));
        $lateMinutes = 0;
        $status = 'normal';
        if ($first <= 0) {
            $status = 'absent';
        } elseif ($scheduledStart > 0 && $first > $scheduledStart + $grace) {
            $status = 'late';
            $lateMinutes = intval(ceil(($first - $scheduledStart) / 60));
        }
        if (count($pairs) === 1 && $last > 0) {
            $status = $status === 'late' ? 'late' : 'missing_checkout';
        }
        $now = time();
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
            'source_updated_at' => $now,
            'calculated_at' => $now,
            'raw_trace' => json_encode([
                'source_record_ids' => array_map(function($row) { return intval($row['id']); }, $records),
                'pairs' => array_map(function($pair) {
                    return [
                        'badge_record_id' => intval($pair['badge_record_id']),
                        'face_record_id' => intval($pair['face_record_id']),
                        'badge_time' => intval($pair['badge_time']),
                        'face_time' => intval($pair['face_time']),
                        'effective_time' => intval($pair['effective_time'])
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
            $where[] = "`status`='" . Database::escape($status) . "'";
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
        if (count($records) === 0 && self::recordHasEnoughFields($event)) {
            $records[] = $event;
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
        $configured = Settings::get('attendance_export_fields', 'work_date,employee_name,employee_no,group_name,scheduled_start,scheduled_end,first_effective_at,last_effective_at,effective_count,status,late_minutes,trace');
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
        if ($field === 'trace') {
            return $row['raw_trace'] ?? '';
        }
        return trim((string)($row[$field] ?? '')) !== '' ? (string)$row[$field] : '-';
    }

    public static function statusText($status)
    {
        $map = [
            'normal' => '正常',
            'exempt' => '免工牌有效',
            'late' => '迟到',
            'absent' => '缺勤',
            'missing_checkout' => '缺少下班有效考勤'
        ];
        return $map[$status] ?? ($status ?: '-');
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
