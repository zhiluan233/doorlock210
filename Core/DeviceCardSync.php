<?php
/*

门禁设备端侧卡库同步模块
Ver 1.0.0.0 20260726
Code by Jason / Codex

*/

namespace anim210System;

class DeviceCardSync {

    const MAX_SLOT_INDEX = 61200;
    const MANUAL_STAGE_UPSERTING = 'upserting';

    public static function enqueueFullSync($deviceId, $source = 'manual')
    {
        return self::enqueueFullSyncJob($deviceId, $source, false);
    }

    public static function enqueueForceFullSync($deviceId, $source = 'manual_force')
    {
        return self::enqueueFullSyncJob($deviceId, $source, true);
    }

    private static function enqueueFullSyncJob($deviceId, $source, $forcePush)
    {
        $device = self::device($deviceId);
        if (!$device || !self::localCardEnabled($device)) {
            return ['ok' => false, 'message' => '设备未启用端侧卡库'];
        }
        if (!$forcePush && !self::initialFullCompleted($device)) {
            $message = '等待管理员手动完成首次端侧卡库全量同步，自动同步已跳过';
            self::cancelAutoQueue($device['id'], $message);
            self::updateDeviceSyncState($device['id'], $message);
            return ['ok' => true, 'queued' => false, 'skipped' => true, 'job_id' => 0, 'message' => $message];
        }

        $existing = self::activeFullJob($device['id']);
        if ($existing && $forcePush && ($existing['status'] ?? '') !== 'running') {
            self::cancelDeviceQueue($device['id'], '手动全量同步已替换未执行任务');
            $existing = null;
        }
        if ($existing) {
            $message = ($existing['status'] ?? '') === 'running' ? '已有端侧卡库全量同步任务正在执行' : '已有端侧卡库全量同步任务等待执行';
            self::updateDeviceSyncState($device['id'], $message);
            return ['ok' => true, 'job_id' => intval($existing['id']), 'message' => $message];
        }
        if ($forcePush) {
            self::cancelDeviceQueue($device['id'], '手动全量同步已替换未执行任务');
            Database::update('devices', [
                'local_card_initial_full_done' => 0,
                'local_card_sync_message' => '手动全量同步准备中'
            ], ['id' => $device['id']]);
        }

        $jobId = self::enqueueJob([
            'device_id' => intval($device['id']),
            'job_type' => $forcePush ? 'full_force' : 'full',
            'source' => $source
        ]);
        $message = $forcePush ? '已提交端侧卡库手动全量下发任务' : '已提交端侧卡库全量同步任务';
        self::updateDeviceSyncState($device['id'], $message);
        return ['ok' => true, 'job_id' => $jobId, 'message' => $message];
    }

    public static function enqueueFullSyncAll($source = 'policy')
    {
        $devices = self::localCardDevices();
        $count = 0;
        $skipped = 0;
        foreach ($devices as $device) {
            $result = self::enqueueFullSync($device['id'], $source);
            if (!empty($result['queued']) || !empty($result['job_id'])) {
                $count++;
            } elseif (!empty($result['skipped'])) {
                $skipped++;
            }
        }
        return ['ok' => true, 'count' => $count, 'skipped' => $skipped, 'message' => '已提交 '.$count.' 台设备端侧卡库全量同步任务，跳过 '.$skipped.' 台未完成首次手动全量同步的设备'];
    }

    public static function enqueueSubjectChange($subjectKind, $subjectId, $source = 'subject_change')
    {
        $subjectKind = self::normalizeSubjectKind($subjectKind);
        $subjectId = trim((string)$subjectId);
        if ($subjectKind === '' || $subjectId === '') {
            return ['ok' => false, 'message' => '同步对象为空'];
        }

        $devices = self::localCardDevices();
        $updated = 0;
        foreach ($devices as $device) {
            if (!self::initialFullCompleted($device)) {
                self::cancelAutoQueue($device['id'], '等待管理员手动完成首次端侧卡库全量同步，自动增量已跳过');
                continue;
            }
            if (self::reconcileSubjectForDevice($device, $subjectKind, $subjectId, $source)) {
                $updated++;
            }
        }
        return ['ok' => true, 'count' => $updated, 'message' => '已提交 '.$updated.' 条端侧卡库增量任务'];
    }

    public static function enqueueSubjectRemoval($subjectKind, $subjectId, $source = 'subject_remove')
    {
        $subjectKind = self::normalizeSubjectKind($subjectKind);
        $subjectId = trim((string)$subjectId);
        if ($subjectKind === '' || $subjectId === '') {
            return ['ok' => false, 'message' => '同步对象为空'];
        }

        $bindings = self::bindingsBySubject($subjectKind, $subjectId);
        $count = 0;
        foreach ($bindings as $binding) {
            $device = self::device($binding['device_id'] ?? 0);
            if (!$device || !self::initialFullCompleted($device)) {
                continue;
            }
            self::enqueueDeleteForBinding($binding, $source);
            $count++;
        }
        return ['ok' => true, 'count' => $count, 'message' => '已提交 '.$count.' 条端侧卡库删除任务'];
    }

    public static function processQueue($limit = null)
    {
        $limit = $limit === null ? Settings::getInt('device_card_sync_batch_size', 20) : intval($limit);
        $limit = max(1, min(1000, $limit));
        $intervalMs = max(0, min(2000, Settings::getInt('device_card_sync_interval_ms', 100)));
        $jobs = self::dueJobs($limit);
        $result = [
            'total' => count($jobs),
            'success' => 0,
            'failed' => 0,
            'generated' => 0,
            'items' => []
        ];

        foreach ($jobs as $index => $job) {
            $isManualFull = ($job['job_type'] ?? '') === 'full_force';
            $item = self::processJob($job, $isManualFull ? $limit : null);
            if ($item['ok']) {
                $result['success']++;
            } else {
                $result['failed']++;
            }
            $result['generated'] += intval($item['generated'] ?? 0);
            $result['items'][] = $item;
            if ($intervalMs > 0 && $index < count($jobs) - 1) {
                usleep($intervalMs * 1000);
            }
            if ($isManualFull) {
                break;
            }
        }

        return $result;
    }

    public static function pendingSummary($deviceId)
    {
        global $conn;

        $deviceId = intval($deviceId);
        $summary = ['pending' => 0, 'failed' => 0, 'running' => 0];
        $sql = "SELECT `status`, COUNT(*) AS `total` FROM `device_card_sync_jobs` WHERE `device_id`={$deviceId} AND `status` IN ('pending','failed','running') GROUP BY `status`";
        $rs = Database::query('device_card_sync_jobs', $sql, '', true);
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $summary[$row['status']] = intval($row['total']);
            }
            mysqli_free_result($rs);
        }
        return $summary;
    }

    public static function manualSyncProgress($deviceId, $jobId)
    {
        global $conn;

        $deviceId = intval($deviceId);
        $jobId = intval($jobId);
        if ($deviceId <= 0 || $jobId <= 0) {
            return ['ok' => false, 'message' => '同步任务参数不合法'];
        }

        $root = Database::querySingleLine('device_card_sync_jobs', "SELECT * FROM `device_card_sync_jobs` WHERE `id`={$jobId} AND `device_id`={$deviceId} AND `job_type` IN ('full','full_force') LIMIT 1", true);
        if (!$root) {
            return ['ok' => false, 'message' => '同步任务不存在'];
        }

        $device = self::device($deviceId);
        if (($root['job_type'] ?? '') === 'full_force') {
            return self::manualFullProgress($device, $root);
        }

        $eligibleTotal = $device ? count(self::desiredSubjectsForDevice($device)) : 0;
        $source = (string)($root['source'] ?? '');
        $sourceSql = mysqli_real_escape_string($conn, $source);
        $counts = [
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'success' => 0,
            'cancelled' => 0
        ];
        $total = 0;
        if ($source !== '') {
            $sql = "SELECT `status`, COUNT(*) AS `total` FROM `device_card_sync_jobs` WHERE `device_id`={$deviceId} AND `source`='{$sourceSql}' AND `job_type` IN ('upsert','delete') GROUP BY `status`";
            $rs = Database::query('device_card_sync_jobs', $sql, '', true);
            if ($rs instanceof \mysqli_result) {
                while ($row = mysqli_fetch_assoc($rs)) {
                    $status = (string)$row['status'];
                    $count = intval($row['total'] ?? 0);
                    if (isset($counts[$status])) {
                        $counts[$status] = $count;
                    }
                    $total += $count;
                }
                mysqli_free_result($rs);
            }
        }

        $rootStatus = (string)($root['status'] ?? '');
        $completed = $counts['success'] + $counts['cancelled'];
        $active = $counts['pending'] + $counts['running'] + $counts['failed'];
        $percent = $total > 0 ? intval(floor(($completed / $total) * 100)) : 0;
        $stage = 'queued';
        if ($rootStatus === 'running') {
            $stage = 'calculating';
        } elseif ($rootStatus === 'failed') {
            $stage = 'failed';
        } elseif ($rootStatus === 'success' && $total === 0) {
            $stage = 'done';
            $percent = 100;
        } elseif ($rootStatus === 'success' && $active > 0) {
            $stage = $counts['failed'] > 0 ? 'retrying' : 'syncing';
        } elseif ($rootStatus === 'success') {
            $stage = 'done';
            $percent = 100;
        }

        return [
            'ok' => true,
            'job_id' => $jobId,
            'device_id' => $deviceId,
            'stage' => $stage,
            'root_status' => $rootStatus,
            'eligible_total' => $eligibleTotal,
            'total' => $total,
            'completed' => $completed,
            'pending' => $counts['pending'],
            'running' => $counts['running'],
            'failed' => $counts['failed'],
            'success' => $counts['success'],
            'cancelled' => $counts['cancelled'],
            'percent' => max(0, min(100, $percent)),
            'done' => $stage === 'done',
            'message' => (string)($root['message'] ?? '')
        ];
    }

    private static function manualFullProgress($device, $root)
    {
        $jobId = intval($root['id'] ?? 0);
        $rootStatus = (string)($root['status'] ?? '');
        $eligibleTotal = $device ? count(self::desiredSubjectsForDevice($device)) : 0;
        $bindingTotal = $device ? self::bindingTotalForDevice($device['id']) : 0;
        $total = $bindingTotal > 0 ? $bindingTotal : $eligibleTotal;
        $slotProgress = max(0, intval($root['slot_index'] ?? 0));

        $completed = $device ? self::bindingCountUpToSlot($device['id'], $slotProgress) : 0;
        if ($rootStatus === 'success') {
            $completed = $total;
            $stageText = 'done';
        } elseif ($rootStatus === 'running') {
            $stageText = 'syncing';
        } elseif ($rootStatus === 'failed') {
            $stageText = 'retrying';
        } else {
            $stageText = 'queued';
        }

        $percent = $total > 0 ? intval(floor(($completed / $total) * 100)) : 100;
        $pending = max(0, $total - $completed);
        return [
            'ok' => true,
            'job_id' => $jobId,
            'device_id' => intval($root['device_id'] ?? 0),
            'stage' => $stageText,
            'root_status' => $rootStatus,
            'eligible_total' => $eligibleTotal,
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'running' => $rootStatus === 'running' ? 1 : 0,
            'failed' => $rootStatus === 'failed' ? 1 : 0,
            'success' => $completed,
            'cancelled' => 0,
            'percent' => max(0, min(100, $percent)),
            'done' => $rootStatus === 'success',
            'message' => (string)($root['message'] ?? '')
        ];
    }

    public static function cancelDeviceQueue($deviceId, $message = '端侧卡库已关闭')
    {
        global $conn;

        $deviceId = intval($deviceId);
        if ($deviceId <= 0) {
            return ['ok' => false, 'message' => '设备ID不合法'];
        }
        $now = time();
        $rawMessage = self::limitText($message, 250);
        $escapedMessage = mysqli_real_escape_string($conn, $rawMessage);
        mysqli_query($conn, "UPDATE `device_card_sync_jobs` SET `status`='cancelled', `message`='{$escapedMessage}', `updated_at`={$now}, `finished_at`={$now}, `locked_at`=0 WHERE `device_id`={$deviceId} AND `status` IN ('pending','failed')");
        self::updateDeviceSyncState($deviceId, $rawMessage);
        return ['ok' => true, 'message' => '已取消该设备未执行的端侧卡库任务'];
    }

    private static function processJob($job, $operationLimit = null)
    {
        $jobId = intval($job['id']);
        self::markJobRunning($jobId);
        if (($job['job_type'] ?? '') === 'full_force') {
            $device = self::device($job['device_id']);
            if (!$device || !self::localCardEnabled($device)) {
                self::markJobSuccess($jobId, '设备未启用端侧卡库，跳过');
                return ['ok' => true, 'job_id' => $jobId, 'done' => true, 'message' => '设备未启用端侧卡库，跳过'];
            }
            return self::processManualFullJob($job, $device, $operationLimit);
        }

        if (($job['job_type'] ?? '') === 'full') {
            $device = self::device($job['device_id']);
            if (!$device || !self::localCardEnabled($device)) {
                self::markJobSuccess($jobId, '设备未启用端侧卡库，跳过');
                return ['ok' => true, 'job_id' => $jobId, 'message' => '设备未启用端侧卡库，跳过'];
            }
            if (!self::initialFullCompleted($device)) {
                $message = '等待管理员手动完成首次端侧卡库全量同步，自动全量任务已取消';
                self::markJobCancelled($jobId, $message);
                self::updateDeviceSyncState($device['id'], $message);
                return ['ok' => true, 'job_id' => $jobId, 'message' => $message];
            }
            $generated = self::reconcileDevice($device, $job['source'] ?: 'full', false);
            $message = '全量差异计算完成，生成 '.$generated.' 条下发任务';
            self::markJobSuccess($jobId, $message);
            Database::update('devices', [
                'local_card_last_full_at' => time(),
                'local_card_sync_message' => $message
            ], ['id' => $device['id']]);
            return ['ok' => true, 'job_id' => $jobId, 'generated' => $generated, 'message' => '全量差异计算完成'];
        }

        $device = self::device($job['device_id']);
        if (!$device || !self::localCardEnabled($device)) {
            self::markJobSuccess($jobId, '设备未启用端侧卡库，跳过');
            return ['ok' => true, 'job_id' => $jobId, 'message' => '设备未启用端侧卡库，跳过'];
        }
        if (!self::initialFullCompleted($device)) {
            $message = '等待管理员手动完成首次端侧卡库全量同步，自动下发任务已取消';
            self::markJobCancelled($jobId, $message);
            self::updateDeviceSyncState($device['id'], $message);
            return ['ok' => true, 'job_id' => $jobId, 'message' => $message];
        }

        $response = self::applyCardJob($device, $job);
        if ($response['ok']) {
            self::finishApplyJob($job);
            self::markJobSuccess($jobId, '下发成功');
            Database::update('devices', [
                'local_card_last_sync_at' => time(),
                'local_card_sync_message' => '最近一次卡库下发成功'
            ], ['id' => $device['id']]);
            return ['ok' => true, 'job_id' => $jobId, 'message' => '下发成功'];
        }

        self::markJobFailed($jobId, intval($job['attempts']) + 1, $response['message']);
        Database::update('devices', [
            'local_card_sync_message' => '卡库下发失败：'.self::limitText($response['message'], 80)
        ], ['id' => $device['id']]);
        return ['ok' => false, 'job_id' => $jobId, 'message' => $response['message']];
    }

    private static function processManualFullJob($job, $device, $operationLimit)
    {
        $jobId = intval($job['id']);
        $limit = $operationLimit === null ? Settings::getInt('device_card_sync_batch_size', 20) : intval($operationLimit);
        $limit = max(1, min(1000, $limit));
        $intervalMs = max(0, min(2000, Settings::getInt('device_card_sync_interval_ms', 50)));
        $processed = 0;
        $progress = intval($job['slot_index'] ?? 0);

        if ($progress <= 0 && ($job['subject_kind'] ?? '') !== self::MANUAL_STAGE_UPSERTING) {
            self::replaceManualBindings($device);
        }

        $bindings = self::manualBindingsAfterSlot($device['id'], $progress, $limit);
        $totalBindings = self::bindingTotalForDevice($device['id']);
        foreach ($bindings as $binding) {
            $jobType = intval($binding['enabled'] ?? 0) === 1 ? 'upsert' : 'delete';
            $response = self::applyCardJob($device, array_merge($binding, ['job_type' => $jobType]));
            if (!$response['ok']) {
                $message = '下发设备卡库 Index '.intval($binding['slot_index']).' 失败：'.self::limitText($response['message'], 120);
                self::markManualJobFailed($jobId, intval($job['attempts'] ?? 0) + 1, $message, self::MANUAL_STAGE_UPSERTING, $progress);
                self::updateDeviceSyncState($device['id'], $message);
                return ['ok' => false, 'job_id' => $jobId, 'generated' => $processed, 'done' => false, 'message' => $message];
            }
            self::finishManualApplyJob(array_merge($binding, ['job_type' => $jobType]));
            $progress = intval($binding['slot_index']);
            $processed++;
            if ($intervalMs > 0 && $processed < $limit) {
                usleep($intervalMs * 1000);
            }
        }

        if (self::hasBindingAfterSlot($device['id'], $progress)) {
            $doneRows = self::bindingCountUpToSlot($device['id'], $progress);
            $message = '正在下发设备卡库：'.$doneRows.'/'.$totalBindings;
            self::markManualJobPending($jobId, self::MANUAL_STAGE_UPSERTING, $progress, $message);
            self::updateDeviceSyncState($device['id'], $message);
            return ['ok' => true, 'job_id' => $jobId, 'generated' => $processed, 'done' => false, 'message' => $message];
        }

        self::cleanupManualClearBindings($device['id']);
        $followupGenerated = self::reconcileDevice($device, 'manual_force_followup', false);
        $message = '手动全量下发完成：处理 '.$totalBindings.' 条设备映射'.($followupGenerated > 0 ? '，补充差异 '.$followupGenerated.' 条' : '');
        self::markJobSuccess($jobId, $message);
        $now = time();
        Database::update('devices', [
            'local_card_initial_full_done' => 1,
            'local_card_last_full_at' => $now,
            'local_card_last_sync_at' => $now,
            'local_card_sync_message' => $message
        ], ['id' => $device['id']]);
        return ['ok' => true, 'job_id' => $jobId, 'generated' => $processed + $followupGenerated, 'done' => true, 'message' => $message];
    }

    private static function reconcileDevice($device, $source = 'full', $forcePush = false)
    {
        $desired = self::desiredSubjectsForDevice($device);
        $bindings = self::bindingsForDevice($device['id']);
        $usedSlots = [];
        foreach ($bindings as $binding) {
            $usedSlots[intval($binding['slot_index'])] = true;
        }

        $generated = 0;
        foreach ($desired as $key => $subject) {
            $binding = $bindings[$key] ?? null;
            if ($binding) {
                if ($forcePush || self::bindingNeedsUpsert($binding, $subject)) {
                    Database::update('device_card_bindings', [
                        'card_id' => $subject['card_id'],
                        'display_name' => $subject['display_name'],
                        'valid_to' => intval($subject['valid_to'] ?? 0),
                        'enabled' => 1,
                        'status' => 'pending',
                        'updated_at' => time()
                    ], ['id' => $binding['id']]);
                    $binding = array_merge($binding, $subject, ['enabled' => 1, 'status' => 'pending']);
                    self::enqueueUpsertForBinding($binding, $source);
                    $generated++;
                }
                continue;
            }

            $slot = self::allocateSlot($usedSlots);
            if ($slot <= 0) {
                self::updateDeviceSyncState($device['id'], '端侧卡库槽位已满，无法继续下发');
                continue;
            }
            $usedSlots[$slot] = true;
            $bindingId = self::insertBinding($device['id'], $slot, $subject);
            $subject['id'] = $bindingId;
            $subject['device_id'] = $device['id'];
            $subject['slot_index'] = $slot;
            self::enqueueUpsertForBinding($subject, $source);
            $generated++;
        }

        foreach ($bindings as $key => $binding) {
            if (!isset($desired[$key])) {
                self::enqueueDeleteForBinding($binding, $source);
                $generated++;
            }
        }

        self::updateDeviceSyncState($device['id'], ($forcePush ? '手动全量下发计算完成，待下发 ' : '全量差异计算完成，待下发 ').$generated.' 条');
        return $generated;
    }

    private static function reconcileSubjectForDevice($device, $subjectKind, $subjectId, $source)
    {
        $binding = self::bindingBySubject($device['id'], $subjectKind, $subjectId);
        $subject = self::desiredSubjectForDevice($device, $subjectKind, $subjectId);
        if (!$subject) {
            if ($binding) {
                self::enqueueDeleteForBinding($binding, $source);
                return true;
            }
            return false;
        }

        if ($binding) {
            if (!self::bindingNeedsUpsert($binding, $subject)) {
                return false;
            }
            Database::update('device_card_bindings', [
                'card_id' => $subject['card_id'],
                'display_name' => $subject['display_name'],
                'valid_to' => intval($subject['valid_to'] ?? 0),
                'enabled' => 1,
                'status' => 'pending',
                'updated_at' => time()
            ], ['id' => $binding['id']]);
            $binding = array_merge($binding, $subject, ['enabled' => 1, 'status' => 'pending']);
            self::enqueueUpsertForBinding($binding, $source);
            return true;
        }

        $usedSlots = self::usedSlots($device['id']);
        $slot = self::allocateSlot($usedSlots);
        if ($slot <= 0) {
            self::updateDeviceSyncState($device['id'], '端侧卡库槽位已满，无法继续下发');
            return false;
        }
        $bindingId = self::insertBinding($device['id'], $slot, $subject);
        $subject['id'] = $bindingId;
        $subject['device_id'] = $device['id'];
        $subject['slot_index'] = $slot;
        self::enqueueUpsertForBinding($subject, $source);
        return true;
    }

    private static function desiredSubjectsForDevice($device)
    {
        $items = [];
        foreach (['employee', 'learner', 'guest'] as $kind) {
            foreach (self::candidateSubjects($kind) as $subject) {
                if (self::subjectCanPass($kind, $subject, $device)) {
                    $items[self::subjectKey($kind, self::subjectId($kind, $subject))] = self::desiredPayload($kind, $subject);
                }
            }
        }
        uksort($items, [self::class, 'compareSubjectKeys']);
        return $items;
    }

    private static function desiredSubjectForDevice($device, $subjectKind, $subjectId)
    {
        $subject = self::findSubject($subjectKind, $subjectId);
        if (!$subject || !self::subjectCanPass($subjectKind, $subject, $device)) {
            return null;
        }
        return self::desiredPayload($subjectKind, $subject);
    }

    private static function candidateSubjects($kind)
    {
        $table = $kind === 'employee' ? 'employee' : ($kind === 'learner' ? 'learner' : 'guest');
        $statusColumn = $kind === 'employee' ? "`status`='true'" : "`status`='true'";
        $where = "`card_id`<>'' AND `card_id` REGEXP '^[0-9]{10}$' AND {$statusColumn}";
        if ($kind === 'guest') {
            $now = time();
            $where .= " AND (`expires_at`=0 OR `expires_at`>{$now})";
        }
        $order = $kind === 'employee' ? "`employee_id` ASC, `open_id` ASC" : ($kind === 'learner' ? "`student_no` ASC" : "`name` ASC, `open_id` ASC");
        $rs = Database::query($table, "SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$order}", '', true);
        $items = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $items[] = $row;
            }
            mysqli_free_result($rs);
        }
        return $items;
    }

    private static function findSubject($kind, $subjectId)
    {
        if ($kind === 'employee') {
            return Database::querySingleLine('employee', ['open_id' => $subjectId]);
        }
        if ($kind === 'learner') {
            return Database::querySingleLine('learner', ['student_no' => $subjectId]);
        }
        if ($kind === 'guest') {
            return Database::querySingleLine('guest', ['open_id' => $subjectId]);
        }
        return null;
    }

    private static function subjectCanPass($kind, $subject, $device)
    {
        $reason = '';
        if ($kind === 'employee') {
            return AttendanceService::canEmployeePass($subject, $device, $reason);
        }
        if ($kind === 'learner') {
            return AttendanceService::canLearnerPass($subject, $device, $reason);
        }
        if ($kind === 'guest') {
            return AttendanceService::canGuestPass($subject, $device, $reason);
        }
        return false;
    }

    private static function desiredPayload($kind, $subject)
    {
        return [
            'subject_kind' => $kind,
            'subject_id' => self::subjectId($kind, $subject),
            'card_id' => AttendanceService::normalizeCardNumber($subject['card_id'] ?? ''),
            'display_name' => self::displayName($kind, $subject),
            'valid_to' => self::validTo($kind, $subject)
        ];
    }

    private static function subjectId($kind, $subject)
    {
        if ($kind === 'employee') {
            return trim((string)($subject['open_id'] ?? ''));
        }
        if ($kind === 'learner') {
            return trim((string)($subject['student_no'] ?? ''));
        }
        return trim((string)($subject['open_id'] ?? ''));
    }

    private static function displayName($kind, $subject)
    {
        $prefix = $kind === 'employee' ? '员-' : ($kind === 'learner' ? '学-' : '访-');
        $name = trim((string)($subject['name'] ?? ''));
        if ($name === '' && $kind === 'learner') {
            $name = trim((string)($subject['realname'] ?? ''));
        }
        $shortName = function_exists('mb_substr') ? mb_substr($name, 0, 2, 'UTF-8') : substr($name, 0, 2);
        return self::safeDeviceName($prefix . $shortName);
    }

    private static function validTo($kind, $subject)
    {
        if ($kind === 'guest') {
            return intval($subject['expires_at'] ?? 0);
        }
        return 0;
    }

    private static function bindingNeedsUpsert($binding, $subject)
    {
        return (string)($binding['card_id'] ?? '') !== (string)$subject['card_id']
            || intval($binding['valid_to'] ?? 0) !== intval($subject['valid_to'] ?? 0)
            || intval($binding['enabled'] ?? 0) !== 1
            || (string)($binding['status'] ?? '') !== 'synced';
    }

    private static function insertBinding($deviceId, $slot, $subject)
    {
        global $conn;

        $now = time();
        Database::insert('device_card_bindings', [
            'id' => null,
            'device_id' => intval($deviceId),
            'slot_index' => intval($slot),
            'subject_kind' => $subject['subject_kind'],
            'subject_id' => $subject['subject_id'],
            'card_id' => $subject['card_id'],
            'display_name' => $subject['display_name'],
            'valid_to' => intval($subject['valid_to'] ?? 0),
            'enabled' => 1,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now
        ]);
        return intval(mysqli_insert_id($conn));
    }

    private static function enqueueUpsertForBinding($binding, $source)
    {
        return self::enqueueJob([
            'device_id' => intval($binding['device_id']),
            'job_type' => 'upsert',
            'subject_kind' => $binding['subject_kind'],
            'subject_id' => $binding['subject_id'],
            'card_id' => $binding['card_id'],
            'slot_index' => intval($binding['slot_index']),
            'display_name' => $binding['display_name'] ?? '',
            'valid_to' => intval($binding['valid_to'] ?? 0),
            'source' => $source
        ]);
    }

    private static function enqueueDeleteForBinding($binding, $source)
    {
        Database::update('device_card_bindings', [
            'enabled' => 0,
            'status' => 'deleting',
            'updated_at' => time()
        ], ['id' => $binding['id']]);
        return self::enqueueJob([
            'device_id' => intval($binding['device_id']),
            'job_type' => 'delete',
            'subject_kind' => $binding['subject_kind'],
            'subject_id' => $binding['subject_id'],
            'card_id' => '0',
            'slot_index' => intval($binding['slot_index']),
            'display_name' => '',
            'valid_to' => self::expiredCardTime(),
            'source' => $source
        ]);
    }

    private static function enqueueJob($data)
    {
        global $conn;

        $now = time();
        self::cancelPendingSlotJobs(
            intval($data['device_id'] ?? 0),
            intval($data['slot_index'] ?? 0)
        );
        $row = [
            'id' => null,
            'device_id' => intval($data['device_id'] ?? 0),
            'job_type' => $data['job_type'] ?? 'upsert',
            'subject_kind' => $data['subject_kind'] ?? '',
            'subject_id' => $data['subject_id'] ?? '',
            'card_id' => $data['card_id'] ?? '',
            'slot_index' => intval($data['slot_index'] ?? 0),
            'display_name' => $data['display_name'] ?? '',
            'valid_to' => intval($data['valid_to'] ?? 0),
            'source' => $data['source'] ?? '',
            'status' => 'pending',
            'attempts' => 0,
            'next_retry' => 0,
            'locked_at' => 0,
            'message' => '',
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => 0
        ];
        Database::insert('device_card_sync_jobs', $row);
        return intval(mysqli_insert_id($conn));
    }

    private static function dueJobs($limit)
    {
        $now = time();
        $sql = "SELECT * FROM `device_card_sync_jobs` WHERE `status` IN ('pending','failed') AND `next_retry`<={$now} ORDER BY CASE WHEN `job_type` IN ('full','full_force') THEN 0 ELSE 1 END, `id` ASC LIMIT ".intval($limit);
        $rs = Database::query('device_card_sync_jobs', $sql, '', true);
        $jobs = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $jobs[] = $row;
            }
            mysqli_free_result($rs);
        }
        return $jobs;
    }

    private static function applyCardJob($device, $job)
    {
        $enabled = ($job['job_type'] ?? '') === 'upsert';
        $slot = intval($job['slot_index'] ?? 0);
        if ($slot <= 0 || $slot > self::MAX_SLOT_INDEX) {
            return ['ok' => false, 'message' => '卡库Index不合法'];
        }

        $fields = [
            'Index' => $slot,
            'isEnb' => $enabled ? 1 : 0,
            'Name' => $enabled ? self::safeDeviceName($job['display_name'] ?? '') : '',
            'Card' => $enabled ? (string)$job['card_id'] : '0',
            'PIN' => '',
            'Year' => 2000,
            'Month' => 0,
            'Day' => 0,
            'Hour' => 0,
            'Minute' => 0,
            'TZ1' => 1
        ];
        $fields = array_merge($fields, self::endTimeFields($enabled ? intval($job['valid_to'] ?? 0) : self::expiredCardTime()));
        return self::postDeviceCard($device, $fields);
    }

    private static function finishApplyJob($job)
    {
        if (($job['job_type'] ?? '') === 'delete') {
            Database::delete('device_card_bindings', [
                'device_id' => $job['device_id'],
                'slot_index' => $job['slot_index']
            ]);
            return;
        }

        $now = time();
        $data = [
            'card_id' => $job['card_id'] ?? '',
            'display_name' => $job['display_name'] ?? '',
            'valid_to' => intval($job['valid_to'] ?? 0),
            'enabled' => 1,
            'status' => 'synced',
            'last_sync_at' => $now,
            'updated_at' => $now
        ];
        $existing = self::bindingBySubject($job['device_id'], $job['subject_kind'], $job['subject_id']);
        if ($existing) {
            Database::update('device_card_bindings', $data, [
                'device_id' => $job['device_id'],
                'subject_kind' => $job['subject_kind'],
                'subject_id' => $job['subject_id']
            ]);
            return;
        }

        Database::insert('device_card_bindings', array_merge([
            'id' => null,
            'device_id' => $job['device_id'],
            'slot_index' => intval($job['slot_index'] ?? 0),
            'subject_kind' => $job['subject_kind'],
            'subject_id' => $job['subject_id'],
            'created_at' => $now
        ], $data));
    }

    private static function finishManualApplyJob($job)
    {
        if (($job['job_type'] ?? '') === 'delete') {
            Database::update('device_card_bindings', [
                'enabled' => 0,
                'status' => 'cleared',
                'last_sync_at' => time(),
                'updated_at' => time()
            ], [
                'device_id' => $job['device_id'],
                'slot_index' => $job['slot_index']
            ]);
            return;
        }
        self::finishApplyJob($job);
    }

    private static function cleanupManualClearBindings($deviceId)
    {
        global $conn;

        $deviceId = intval($deviceId);
        if ($deviceId <= 0) {
            return;
        }
        mysqli_query($conn, "DELETE FROM `device_card_bindings` WHERE `device_id`={$deviceId} AND `subject_kind`='clear'");
    }

    private static function postDeviceCard($device, $fields)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'PHP cURL 扩展未启用'];
        }
        $path = trim(Settings::get('device_card_edit_path', '/EditCard.shtm'));
        if ($path === '') {
            $path = '/EditCard.shtm';
        }
        $url = self::deviceUrl($device['ip'] ?? '', $path);
        if ($url === '') {
            return ['ok' => false, 'message' => '设备IP为空'];
        }

        $timeout = max(1, min(30, Settings::getInt('device_card_sync_timeout', 3)));
        $username = Settings::get('remote_open_username', 'admin');
        $password = Settings::get('remote_open_password', '888888');
        $body = http_build_query(self::deviceFormFields($fields), '', '&');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        if ($username !== '' || $password !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, defined('CURLAUTH_ANY') ? CURLAUTH_ANY : CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }
        $raw = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '') {
            return ['ok' => false, 'message' => $error];
        }
        if ($httpCode >= 200 && $httpCode < 400 && self::deviceResponseOk($raw)) {
            return ['ok' => true, 'message' => 'HTTP '.$httpCode, 'raw' => $raw];
        }
        return ['ok' => false, 'message' => '设备返回HTTP '.$httpCode.' '.self::responseSummary($raw), 'raw' => $raw];
    }

    private static function deviceResponseOk($body)
    {
        $body = trim((string)$body);
        if ($body === '') {
            return true;
        }
        foreach (['invalid', 'error', 'fail', 'denied', 'unauthorized', 'forbidden', '失败', '错误', '无效', '拒绝'] as $keyword) {
            if (stripos($body, $keyword) !== false) {
                return false;
            }
        }
        return true;
    }

    private static function endTimeFields($validTo)
    {
        if ($validTo > 0) {
            $parts = getdate($validTo);
            return [
                'YearB' => intval($parts['year']),
                'MonthB' => intval($parts['mon']),
                'DayB' => intval($parts['mday']),
                'HourB' => intval($parts['hours']),
                'MinuteB' => intval($parts['minutes'])
            ];
        }
        return ['YearB' => 2090, 'MonthB' => 12, 'DayB' => 31, 'HourB' => 23, 'MinuteB' => 59];
    }

    private static function deviceUrl($deviceIp, $path)
    {
        $deviceIp = trim((string)$deviceIp);
        if ($deviceIp === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }
        if (!preg_match('/^https?:\/\//i', $deviceIp)) {
            $deviceIp = 'http://' . $deviceIp;
        }
        return rtrim($deviceIp, '/') . (strpos($path, '/') === 0 ? $path : '/' . $path);
    }

    private static function markJobRunning($jobId)
    {
        Database::update('device_card_sync_jobs', [
            'status' => 'running',
            'locked_at' => time(),
            'updated_at' => time()
        ], ['id' => $jobId]);
    }

    private static function cancelPendingSlotJobs($deviceId, $slotIndex)
    {
        global $conn;

        $deviceId = intval($deviceId);
        $slotIndex = intval($slotIndex);
        if ($deviceId <= 0 || $slotIndex <= 0) {
            return;
        }
        $now = time();
        mysqli_query($conn, "UPDATE `device_card_sync_jobs` SET `status`='cancelled', `updated_at`={$now}, `finished_at`={$now}, `message`='已被新的同槽位任务替换' WHERE `device_id`={$deviceId} AND `slot_index`={$slotIndex} AND `status` IN ('pending','failed')");
    }

    private static function markJobSuccess($jobId, $message)
    {
        Database::update('device_card_sync_jobs', [
            'status' => 'success',
            'message' => $message,
            'updated_at' => time(),
            'finished_at' => time()
        ], ['id' => $jobId]);
    }

    private static function markJobFailed($jobId, $attempts, $message)
    {
        $base = max(30, Settings::getInt('queue_retry_base_seconds', 60));
        $max = max($base, Settings::getInt('queue_retry_max_seconds', 3600));
        $delay = min($max, $base * max(1, min(20, $attempts)));
        Database::update('device_card_sync_jobs', [
            'status' => 'failed',
            'attempts' => $attempts,
            'next_retry' => time() + $delay,
            'message' => $message,
            'locked_at' => 0,
            'updated_at' => time()
        ], ['id' => $jobId]);
    }

    private static function updateDeviceSyncState($deviceId, $message)
    {
        Database::update('devices', [
            'local_card_sync_message' => self::limitText($message, 250)
        ], ['id' => $deviceId]);
    }

    private static function bindingsForDevice($deviceId)
    {
        $deviceId = intval($deviceId);
        $rs = Database::query('device_card_bindings', "SELECT * FROM `device_card_bindings` WHERE `device_id`={$deviceId}", '', true);
        $bindings = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $bindings[self::subjectKey($row['subject_kind'], $row['subject_id'])] = $row;
            }
            mysqli_free_result($rs);
        }
        return $bindings;
    }

    private static function bindingBySubject($deviceId, $kind, $subjectId)
    {
        return Database::querySingleLine('device_card_bindings', [
            'device_id' => intval($deviceId),
            'subject_kind' => $kind,
            'subject_id' => $subjectId
        ]);
    }

    private static function bindingsBySubject($kind, $subjectId)
    {
        global $conn;

        $kind = mysqli_real_escape_string($conn, $kind);
        $subjectId = mysqli_real_escape_string($conn, $subjectId);
        $sql = "SELECT b.* FROM `device_card_bindings` b INNER JOIN `devices` d ON d.`id`=b.`device_id` WHERE b.`subject_kind`='{$kind}' AND b.`subject_id`='{$subjectId}' AND d.`local_card_enabled`=1";
        $rs = Database::query('device_card_bindings', $sql, '', true);
        $items = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $items[] = $row;
            }
            mysqli_free_result($rs);
        }
        return $items;
    }

    private static function usedSlots($deviceId)
    {
        $deviceId = intval($deviceId);
        $rs = Database::query('device_card_bindings', "SELECT `slot_index` FROM `device_card_bindings` WHERE `device_id`={$deviceId}", '', true);
        $used = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $used[intval($row['slot_index'])] = true;
            }
            mysqli_free_result($rs);
        }
        return $used;
    }

    private static function allocateSlot($usedSlots)
    {
        for ($i = 1; $i <= self::MAX_SLOT_INDEX; $i++) {
            if (empty($usedSlots[$i])) {
                return $i;
            }
        }
        return 0;
    }

    private static function device($deviceId)
    {
        return Database::querySingleLine('devices', ['id' => intval($deviceId)]);
    }

    private static function localCardDevices()
    {
        $rs = Database::query('devices', "SELECT * FROM `devices` WHERE `local_card_enabled`=1 ORDER BY `id` ASC", '', true);
        $devices = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $devices[] = $row;
            }
            mysqli_free_result($rs);
        }
        return $devices;
    }

    private static function activeFullJob($deviceId)
    {
        $deviceId = intval($deviceId);
        if ($deviceId <= 0) {
            return null;
        }
        $sql = "SELECT * FROM `device_card_sync_jobs` WHERE `device_id`={$deviceId} AND `job_type` IN ('full','full_force') AND `status` IN ('pending','running') ORDER BY `id` ASC LIMIT 1";
        $job = Database::querySingleLine('device_card_sync_jobs', $sql, true);
        return is_array($job) ? $job : null;
    }

    private static function initialFullCompleted($device)
    {
        return intval($device['local_card_initial_full_done'] ?? 0) === 1;
    }

    private static function cancelAutoQueue($deviceId, $message)
    {
        global $conn;

        $deviceId = intval($deviceId);
        if ($deviceId <= 0) {
            return;
        }
        $now = time();
        $message = mysqli_real_escape_string($conn, self::limitText($message, 250));
        mysqli_query($conn, "UPDATE `device_card_sync_jobs` SET `status`='cancelled', `message`='{$message}', `updated_at`={$now}, `finished_at`={$now}, `locked_at`=0 WHERE `device_id`={$deviceId} AND `job_type`<>'full_force' AND `status` IN ('pending','failed')");
    }

    private static function replaceManualBindings($device)
    {
        global $conn;

        $deviceId = intval($device['id'] ?? 0);
        if ($deviceId <= 0) {
            return 0;
        }

        self::cancelAutoQueue($deviceId, '手动全量同步已重建设备映射');
        $desired = self::desiredSubjectsForDevice($device);
        $oldBindings = self::bindingsBySlot($deviceId);
        $planned = [];
        $usedSlots = [];
        $now = time();

        foreach ($oldBindings as $slot => $binding) {
            $key = self::subjectKey($binding['subject_kind'] ?? '', $binding['subject_id'] ?? '');
            if (isset($desired[$key])) {
                $planned[intval($slot)] = $desired[$key];
                $usedSlots[intval($slot)] = true;
                unset($desired[$key]);
            }
        }

        foreach ($desired as $subject) {
            $slot = self::allocateSlot($usedSlots);
            if ($slot <= 0) {
                self::updateDeviceSyncState($deviceId, '端侧卡库槽位已满，无法继续下发');
                break;
            }
            $planned[$slot] = $subject;
            $usedSlots[$slot] = true;
        }
        ksort($planned);

        mysqli_query($conn, "DELETE FROM `device_card_bindings` WHERE `device_id`={$deviceId}");
        foreach ($planned as $slot => $subject) {
            Database::insert('device_card_bindings', [
                'id' => null,
                'device_id' => $deviceId,
                'slot_index' => intval($slot),
                'subject_kind' => $subject['subject_kind'],
                'subject_id' => $subject['subject_id'],
                'card_id' => $subject['card_id'],
                'display_name' => $subject['display_name'],
                'valid_to' => intval($subject['valid_to'] ?? 0),
                'enabled' => 1,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        foreach ($oldBindings as $slot => $old) {
            $slot = intval($slot);
            if (isset($planned[$slot])) {
                continue;
            }
            Database::insert('device_card_bindings', [
                'id' => null,
                'device_id' => $deviceId,
                'slot_index' => $slot,
                'subject_kind' => 'clear',
                'subject_id' => 'slot:' . $slot,
                'card_id' => '0',
                'display_name' => '',
                'valid_to' => self::expiredCardTime(),
                'enabled' => 0,
                'status' => 'deleting',
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }

        $count = self::bindingTotalForDevice($deviceId);
        self::updateDeviceSyncState($deviceId, '手动全量同步映射已重建，待下发 '.$count.' 条');
        return $count;
    }

    private static function bindingsBySlot($deviceId)
    {
        $deviceId = intval($deviceId);
        $rs = Database::query('device_card_bindings', "SELECT * FROM `device_card_bindings` WHERE `device_id`={$deviceId} ORDER BY `slot_index` ASC", '', true);
        $items = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $items[intval($row['slot_index'])] = $row;
            }
            mysqli_free_result($rs);
        }
        return $items;
    }

    private static function manualBindingsAfterSlot($deviceId, $slotIndex, $limit)
    {
        $deviceId = intval($deviceId);
        $slotIndex = intval($slotIndex);
        $limit = max(1, intval($limit));
        $rs = Database::query('device_card_bindings', "SELECT * FROM `device_card_bindings` WHERE `device_id`={$deviceId} AND `slot_index`>{$slotIndex} ORDER BY `slot_index` ASC LIMIT {$limit}", '', true);
        $items = [];
        if ($rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $items[] = $row;
            }
            mysqli_free_result($rs);
        }
        return $items;
    }

    private static function hasBindingAfterSlot($deviceId, $slotIndex)
    {
        $deviceId = intval($deviceId);
        $slotIndex = intval($slotIndex);
        $row = Database::querySingleLine('device_card_bindings', "SELECT `id` FROM `device_card_bindings` WHERE `device_id`={$deviceId} AND `slot_index`>{$slotIndex} ORDER BY `slot_index` ASC LIMIT 1", true);
        return is_array($row) && !empty($row['id']);
    }

    private static function bindingTotalForDevice($deviceId)
    {
        $deviceId = intval($deviceId);
        $row = Database::querySingleLine('device_card_bindings', "SELECT COUNT(*) AS `total` FROM `device_card_bindings` WHERE `device_id`={$deviceId}", true);
        return intval($row['total'] ?? 0);
    }

    private static function bindingCountUpToSlot($deviceId, $slotIndex)
    {
        $deviceId = intval($deviceId);
        $slotIndex = intval($slotIndex);
        if ($deviceId <= 0 || $slotIndex <= 0) {
            return 0;
        }
        $row = Database::querySingleLine('device_card_bindings', "SELECT COUNT(*) AS `total` FROM `device_card_bindings` WHERE `device_id`={$deviceId} AND `slot_index`<={$slotIndex}", true);
        return intval($row['total'] ?? 0);
    }


    private static function markJobCancelled($jobId, $message)
    {
        Database::update('device_card_sync_jobs', [
            'status' => 'cancelled',
            'message' => $message,
            'locked_at' => 0,
            'updated_at' => time(),
            'finished_at' => time()
        ], ['id' => $jobId]);
    }

    private static function markManualJobPending($jobId, $stage, $slotIndex, $message)
    {
        Database::update('device_card_sync_jobs', [
            'status' => 'pending',
            'subject_kind' => $stage,
            'slot_index' => intval($slotIndex),
            'message' => $message,
            'locked_at' => 0,
            'next_retry' => 0,
            'updated_at' => time()
        ], ['id' => $jobId]);
    }

    private static function markManualJobFailed($jobId, $attempts, $message, $stage, $slotIndex)
    {
        $base = max(30, Settings::getInt('queue_retry_base_seconds', 60));
        $max = max($base, Settings::getInt('queue_retry_max_seconds', 3600));
        $delay = min($max, $base * max(1, min(20, $attempts)));
        Database::update('device_card_sync_jobs', [
            'status' => 'failed',
            'subject_kind' => $stage,
            'slot_index' => intval($slotIndex),
            'attempts' => $attempts,
            'next_retry' => time() + $delay,
            'message' => $message,
            'locked_at' => 0,
            'updated_at' => time()
        ], ['id' => $jobId]);
    }

    private static function expiredCardTime()
    {
        return strtotime('yesterday 23:59:59') ?: (time() - 86400);
    }

    private static function deviceFormFields($fields)
    {
        if (isset($fields['Name'])) {
            $fields['Name'] = self::encodeDeviceFormText($fields['Name']);
        }
        return $fields;
    }

    private static function encodeDeviceFormText($value)
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $encoded = @iconv('UTF-8', 'GBK//IGNORE', $value);
            if ($encoded !== false && $encoded !== '') {
                return $encoded;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            $encoded = @mb_convert_encoding($value, 'GBK', 'UTF-8');
            if (is_string($encoded) && $encoded !== '') {
                return $encoded;
            }
        }
        return $value;
    }

    private static function localCardEnabled($device)
    {
        return intval($device['local_card_enabled'] ?? 0) === 1;
    }

    private static function subjectKey($kind, $subjectId)
    {
        return $kind . ':' . $subjectId;
    }

    private static function compareSubjectKeys($left, $right)
    {
        [$leftKind, $leftId] = array_pad(explode(':', (string)$left, 2), 2, '');
        [$rightKind, $rightId] = array_pad(explode(':', (string)$right, 2), 2, '');
        $leftOrder = self::subjectKindOrder($leftKind);
        $rightOrder = self::subjectKindOrder($rightKind);
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }
        return strcmp($leftId, $rightId);
    }

    private static function subjectKindOrder($kind)
    {
        if ($kind === 'employee') {
            return 1;
        }
        if ($kind === 'learner') {
            return 2;
        }
        if ($kind === 'guest') {
            return 3;
        }
        return 99;
    }

    private static function normalizeSubjectKind($kind)
    {
        $kind = trim((string)$kind);
        return in_array($kind, ['employee', 'learner', 'guest'], true) ? $kind : '';
    }

    private static function safeDeviceName($name)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string)$name));
        if ($name === '') {
            return 'user';
        }
        return function_exists('mb_substr') ? mb_substr($name, 0, 4, 'UTF-8') : substr($name, 0, 4);
    }

    private static function responseSummary($body)
    {
        $body = trim(preg_replace('/\s+/', ' ', (string)$body));
        if ($body === '') {
            return '';
        }
        return self::limitText($body, 120);
    }

    private static function limitText($text, $limit)
    {
        $text = (string)$text;
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }
}
