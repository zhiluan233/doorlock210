<?php
/*

飞书通讯录后台同步模块
Ver 1.0.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

class FeishuContactSync {

    public static function enqueueFullSync($requestedBy = '', $source = 'manual')
    {
        global $conn;

        $activeJob = self::activeJob();
        if ($activeJob) {
            return [
                'ok' => true,
                'queued' => false,
                'job_id' => intval($activeJob['id']),
                'message' => '已有通讯录同步任务正在等待或执行中'
            ];
        }

        $now = time();
        $result = Database::insert('feishu_sync_jobs', [
            'job_type' => 'full_contact',
            'source' => $source,
            'status' => 'pending',
            'requested_by' => $requestedBy,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        if ($result !== true) {
            return ['ok' => false, 'queued' => false, 'job_id' => 0, 'message' => $result];
        }

        $jobId = mysqli_insert_id($conn);
        return [
            'ok' => true,
            'queued' => true,
            'job_id' => intval($jobId),
            'message' => '通讯录同步任务已提交，计划任务会在后台执行'
        ];
    }

    public static function scheduleDailyIfDue()
    {
        if (!Settings::getBool('feishu_contact_sync_enabled', true)) {
            return ['due' => false, 'message' => '通讯录定时同步未启用'];
        }

        $timeText = Settings::get('feishu_contact_sync_daily_time', '03:25');
        if (!preg_match('/^\d{2}:\d{2}$/', $timeText)) {
            $timeText = '03:25';
        }
        if (date('H:i') < $timeText) {
            return ['due' => false, 'message' => '未到通讯录定时同步时间'];
        }

        $today = date('Y-m-d');
        if (Settings::get('feishu_contact_sync_last_date', '') === $today) {
            return ['due' => false, 'message' => '今日通讯录同步已完成'];
        }

        $todayJob = self::todayDailyJob();
        if ($todayJob && in_array($todayJob['status'], ['pending', 'running'], true)) {
            return ['due' => true, 'message' => '今日通讯录同步任务已在队列中', 'job_id' => intval($todayJob['id'])];
        }
        if ($todayJob && $todayJob['status'] === 'success') {
            Settings::set('feishu_contact_sync_last_date', $today);
            return ['due' => false, 'message' => '今日通讯录同步已完成', 'job_id' => intval($todayJob['id'])];
        }

        $result = self::enqueueFullSync('cron', 'daily');
        return [
            'due' => $result['ok'],
            'message' => $result['message'],
            'job_id' => $result['job_id'] ?? 0
        ];
    }

    public static function processNextJob($limit = 1)
    {
        $limit = max(1, intval($limit));
        $results = [];
        for ($i = 0; $i < $limit; $i++) {
            $job = self::claimJob();
            if (!$job) {
                break;
            }
            $results[] = self::runFullSync($job);
        }
        if (count($results) === 0) {
            return ['total' => 0, 'jobs' => []];
        }
        return ['total' => count($results), 'jobs' => $results];
    }

    public static function applyUserEvent($payload, $eventType)
    {
        if (!self::shouldApplyUserLifecycleEvent($payload, $eventType)) {
            return '';
        }

        $identity = self::extractEventIdentity($payload);
        if (!$identity['open_id'] && !$identity['user_id'] && !$identity['employee_id']) {
            return '';
        }

        $exists = self::findEmployee($identity);
        $lifecycle = self::eventLifecycle($payload, $eventType);
        if ($lifecycle === 'unknown') {
            return '';
        }
        if ($lifecycle === 'deleted') {
            if ($exists) {
                self::deleteEmployee($exists);
                self::markIncrementalSync($eventType);
                return $exists['open_id'] ?: $identity['open_id'];
            }
            return $identity['open_id'];
        }

        $active = $lifecycle === 'active';
        $user = $identity['user'];
        $now = time();
        $data = [
            'name' => $user['name'] ?? $user['employee_name'] ?? $user['display_name'] ?? ($exists['name'] ?? ''),
            'employee_id' => $identity['employee_id'] ?: ($exists['employee_id'] ?? ''),
            'realname' => $user['realname'] ?? $user['real_name'] ?? ($exists['realname'] ?? '--'),
            'status' => $active ? 'true' : 'false',
            'department_id' => self::firstValue($user['department_ids'] ?? $user['department_id'] ?? ($exists['department_id'] ?? '')),
            'department_ids' => isset($user['department_ids']) ? json_encode(self::arrayValue($user['department_ids']), JSON_UNESCAPED_UNICODE) : ($exists['department_ids'] ?? ''),
            'user_id' => $identity['user_id'] ?: ($exists['user_id'] ?? ''),
            'union_id' => $user['union_id'] ?? ($exists['union_id'] ?? ''),
            'email' => $user['email'] ?? ($exists['email'] ?? ''),
            'mobile' => $user['mobile'] ?? ($exists['mobile'] ?? ''),
            'tenant_key' => $payload['header']['tenant_key'] ?? ($exists['tenant_key'] ?? ''),
            'updated_at' => $now
        ];
        if ($identity['open_id'] !== '') {
            $data['open_id'] = $identity['open_id'];
        }
        if (!$active) {
            $data['card_id'] = '';
        }

        if ($exists) {
            unset($data['open_id']);
            Database::update('employee', $data, ['id' => $exists['id']]);
            self::markIncrementalSync($eventType);
            return $exists['open_id'] ?: $identity['open_id'];
        }

        if ($identity['open_id'] === '') {
            return '';
        }
        Database::insert('employee', $data);
        self::markIncrementalSync($eventType);
        return $identity['open_id'];
    }

    private static function runFullSync($job)
    {
        $jobId = intval($job['id']);
        $now = time();
        self::updateJob($jobId, ['started_at' => $now, 'message' => '开始拉取飞书通讯录']);

        $feishu = new appLinkFeishu();
        $members = $feishu->getFeishuMemberList();
        if ($feishu->getLastError() !== '') {
            $message = $feishu->getLastError();
            self::updateJob($jobId, ['status' => 'failed', 'finished_at' => time(), 'message' => $message]);
            return ['job_id' => $jobId, 'status' => 'failed', 'message' => $message];
        }
        if (!is_array($members) || count($members) === 0) {
            $message = '未获取到飞书通讯录，已取消本次同步，避免误释放本地员工卡号';
            self::updateJob($jobId, ['status' => 'failed', 'finished_at' => time(), 'message' => $message]);
            return ['job_id' => $jobId, 'status' => 'failed', 'message' => $message];
        }

        self::syncDepartments($feishu->getLastDepartments());

        $stats = [
            'total_count' => 0,
            'insert_count' => 0,
            'update_count' => 0,
            'disable_count' => 0,
            'delete_count' => 0,
            'release_count' => 0
        ];
        $seenOpenIds = [];

        foreach ($members as $member) {
            if (empty($member['open_id'])) {
                continue;
            }
            $stats['total_count']++;
            $seenOpenIds[$member['open_id']] = true;
            $exists = Database::querySingleLine('employee', ['open_id' => $member['open_id']]);
            if (($member['lifecycle'] ?? '') === 'deleted') {
                if ($exists) {
                    self::deleteEmployee($exists, $stats);
                }
                continue;
            }

            $active = ($member['status'] === true);
            if (!$active && $exists && !empty($exists['card_id'])) {
                $stats['release_count']++;
            }
            if (!$active && (!$exists || ($exists['status'] ?? '') === 'true')) {
                $stats['disable_count']++;
            }

            $data = [
                'open_id' => $member['open_id'],
                'user_id' => $member['user_id'],
                'union_id' => $member['union_id'],
                'name' => $member['name'],
                'employee_id' => $member['employee_no'],
                'realname' => $member['real_name'],
                'status' => $active ? 'true' : 'false',
                'department_id' => $member['department_id'],
                'department_name' => $member['department_name'],
                'department_ids' => json_encode($member['department_ids'], JSON_UNESCAPED_UNICODE),
                'email' => $member['email'],
                'mobile' => $member['mobile'],
                'tenant_key' => $member['tenant_key'],
                'updated_at' => time()
            ];
            if (!$active) {
                $data['card_id'] = '';
            }

            if ($exists) {
                unset($data['open_id']);
                Database::update('employee', $data, ['open_id' => $member['open_id']]);
                $stats['update_count']++;
            } else {
                Database::insert('employee', $data);
                $stats['insert_count']++;
            }
        }

        if (Settings::getBool('feishu_contact_sync_release_missing', true)) {
            self::deleteMissingEmployees($seenOpenIds, $stats);
        }

        $message = '飞书通讯录同步完成：处理 '.$stats['total_count'].' 人，新增 '.$stats['insert_count'].' 人，更新 '.$stats['update_count'].' 人，禁用 '.$stats['disable_count'].' 人，删除 '.$stats['delete_count'].' 人，释放卡号 '.$stats['release_count'].' 张';
        $finishedAt = time();
        $stats['status'] = 'success';
        $stats['finished_at'] = $finishedAt;
        $stats['message'] = $message;
        self::updateJob($jobId, $stats);
        Settings::set('feishu_contact_sync_last_full_at', (string)$finishedAt);

        if (($job['source'] ?? '') === 'daily') {
            Settings::set('feishu_contact_sync_last_date', date('Y-m-d'));
        }

        return ['job_id' => $jobId, 'status' => 'success', 'message' => $message];
    }

    private static function syncDepartments($departments)
    {
        global $conn;

        if (!is_array($departments) || count($departments) === 0) {
            return;
        }

        $now = time();
        $seen = [];
        foreach ($departments as $department) {
            $departmentId = trim((string)($department['department_id'] ?? ''));
            if ($departmentId === '') {
                continue;
            }
            $seen[] = "'" . mysqli_real_escape_string($conn, $departmentId) . "'";
            $row = [
                'department_id' => $departmentId,
                'open_department_id' => $department['open_department_id'] ?? '',
                'parent_department_id' => $department['parent_department_id'] ?? '',
                'name' => $department['name'] ?? $departmentId,
                'i18n_name' => json_encode($department['i18n_name'] ?? [], JSON_UNESCAPED_UNICODE),
                'leader_user_id' => $department['leader_user_id'] ?? '',
                'member_count' => intval($department['member_count'] ?? 0),
                'status' => 'active',
                'raw_payload' => json_encode($department['raw_payload'] ?? $department, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now
            ];
            $columns = [];
            $values = [];
            $updates = [];
            foreach ($row as $key => $value) {
                $columns[] = "`" . mysqli_real_escape_string($conn, $key) . "`";
                $values[] = "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
                if ($key !== 'department_id' && $key !== 'created_at') {
                    $updates[] = "`" . mysqli_real_escape_string($conn, $key) . "`=VALUES(`" . mysqli_real_escape_string($conn, $key) . "`)";
                }
            }
            $sql = "INSERT INTO `feishu_departments` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ") ON DUPLICATE KEY UPDATE " . implode(',', $updates);
            mysqli_query($conn, $sql);
        }

        if (count($seen) > 0) {
            mysqli_query($conn, "UPDATE `feishu_departments` SET `status`='deleted', `updated_at`={$now} WHERE `status`<>'deleted' AND `department_id` NOT IN (" . implode(',', $seen) . ")");
        }
    }

    private static function deleteMissingEmployees($seenOpenIds, &$stats)
    {
        $rs = Database::query('employee', "SELECT * FROM `employee` WHERE `open_id`<>''", '', true);
        if (!$rs || !($rs instanceof \mysqli_result)) {
            return;
        }

        while ($employee = mysqli_fetch_assoc($rs)) {
            if (isset($seenOpenIds[$employee['open_id']])) {
                continue;
            }
            self::deleteEmployee($employee, $stats);
        }
    }

    private static function deleteEmployee($employee, &$stats = null)
    {
        $openId = $employee['open_id'] ?? '';
        $employeeId = $employee['employee_id'] ?? '';

        if (is_array($stats)) {
            $stats['delete_count'] = ($stats['delete_count'] ?? 0) + 1;
            if (!empty($employee['card_id'])) {
                $stats['release_count'] = ($stats['release_count'] ?? 0) + 1;
            }
        }

        if ($openId !== '') {
            Database::delete('access_policies', [
                'subject_kind' => 'employee',
                'subject_type' => 'employee',
                'subject_value' => $openId
            ]);
            Database::delete('access_role_members', ['employee_open_id' => $openId]);
            self::removeEmployeeFromLegacyDeviceLists($openId);
        }
        self::deleteLinkedPanelUsers($openId, $employeeId);
        Database::delete('employee', ['id' => $employee['id']]);
    }

    private static function deleteLinkedPanelUsers($openId, $employeeId)
    {
        if ($openId !== '') {
            Database::delete('user', ['open_id' => $openId]);
        }
        if ($employeeId === '') {
            return;
        }

        $employeeId = Database::escape($employeeId);
        $rs = Database::query('user', "SELECT * FROM `user` WHERE `employee_id`='{$employeeId}'", '', true);
        if (!$rs || !($rs instanceof \mysqli_result)) {
            return;
        }

        while ($user = mysqli_fetch_assoc($rs)) {
            if (!empty($user['open_id']) || strpos($user['username'] ?? '', 'fs_') === 0) {
                Database::delete('user', ['id' => $user['id']]);
            }
        }
    }

    private static function removeEmployeeFromLegacyDeviceLists($openId)
    {
        if ($openId === '') {
            return;
        }

        $rs = Database::query('devices', "SELECT `id`, `allowedEmployee` FROM `devices` WHERE `allowedEmployee`<>''", '', true);
        if (!$rs || !($rs instanceof \mysqli_result)) {
            return;
        }

        while ($device = mysqli_fetch_assoc($rs)) {
            $list = json_decode($device['allowedEmployee'] ?? '[]', true);
            if (!is_array($list)) {
                continue;
            }
            $filtered = [];
            $changed = false;
            foreach ($list as $item) {
                $value = is_array($item) ? ($item['value'] ?? '') : $item;
                if ($value === $openId) {
                    $changed = true;
                    continue;
                }
                $filtered[] = $item;
            }
            if ($changed) {
                Database::update('devices', [
                    'allowedEmployee' => json_encode($filtered, JSON_UNESCAPED_UNICODE)
                ], ['id' => $device['id']]);
            }
        }
    }

    private static function activeJob()
    {
        $now = time();
        $staleBefore = $now - 1800;
        $sql = "SELECT * FROM `feishu_sync_jobs` WHERE `job_type`='full_contact' AND (`status`='pending' OR (`status`='running' AND `locked_at`>{$staleBefore})) ORDER BY `id` DESC LIMIT 1";
        return Database::querySingleLine('feishu_sync_jobs', $sql, true);
    }

    private static function todayDailyJob()
    {
        $start = strtotime(date('Y-m-d 00:00:00'));
        $sql = "SELECT * FROM `feishu_sync_jobs` WHERE `job_type`='full_contact' AND `source`='daily' AND `created_at`>={$start} ORDER BY `id` DESC LIMIT 1";
        return Database::querySingleLine('feishu_sync_jobs', $sql, true);
    }

    private static function claimJob()
    {
        global $conn;

        $now = time();
        $staleBefore = $now - 1800;
        $sql = "SELECT * FROM `feishu_sync_jobs` WHERE `job_type`='full_contact' AND (`status`='pending' OR (`status`='running' AND `locked_at`<={$staleBefore})) ORDER BY `id` ASC LIMIT 1";
        $job = Database::querySingleLine('feishu_sync_jobs', $sql, true);
        if (!$job) {
            return null;
        }

        $id = intval($job['id']);
        mysqli_query($conn, "UPDATE `feishu_sync_jobs` SET `status`='running', `locked_at`={$now}, `updated_at`={$now} WHERE `id`={$id}");
        return Database::querySingleLine('feishu_sync_jobs', ['id' => $id]);
    }

    private static function updateJob($jobId, $data)
    {
        $data['updated_at'] = time();
        return Database::update('feishu_sync_jobs', $data, ['id' => $jobId]);
    }

    private static function markIncrementalSync($eventType)
    {
        $now = time();
        Settings::set('feishu_contact_incremental_last_at', (string)$now);
        Settings::set('feishu_contact_incremental_last_event', $eventType);
    }

    private static function findEmployee($identity)
    {
        if ($identity['open_id'] !== '') {
            $employee = Database::querySingleLine('employee', ['open_id' => $identity['open_id']]);
            if ($employee) {
                return $employee;
            }
        }
        if ($identity['user_id'] !== '') {
            $employee = Database::querySingleLine('employee', ['user_id' => $identity['user_id']]);
            if ($employee) {
                return $employee;
            }
        }
        if ($identity['employee_id'] !== '') {
            return Database::querySingleLine('employee', ['employee_id' => $identity['employee_id']]);
        }
        return null;
    }

    private static function extractEventIdentity($payload)
    {
        $event = $payload['event'] ?? $payload;
        $user = $event['user'] ?? $event['object'] ?? $event['employee'] ?? $event['employment'] ?? $event;
        return [
            'open_id' => $user['open_id'] ?? $user['open_user_id'] ?? $event['open_id'] ?? '',
            'user_id' => $user['user_id'] ?? $event['user_id'] ?? '',
            'employee_id' => $user['employee_no'] ?? $user['employee_id'] ?? $user['employee_number'] ?? $event['employee_no'] ?? $event['employee_id'] ?? '',
            'user' => $user
        ];
    }

    private static function shouldApplyUserLifecycleEvent($payload, $eventType)
    {
        $eventText = strtolower(trim((string)$eventType));
        if ($eventText === '') {
            return self::payloadHasLifecycleStatus($payload);
        }

        if (preg_match('/^(attendance|approval|calendar|im|message|task|doc|drive|meeting|vc)\./', $eventText)) {
            return false;
        }
        if (preg_match('/^contact\.(user|employee)\./', $eventText)) {
            return true;
        }
        if (preg_match('/^(corehr|ehr|personnel|employee|employment|staff)\./', $eventText)) {
            return true;
        }
        if (
            preg_match('/(resign|resigned|resignation|exit|exited|terminate|terminated|offboard|offboarding|offboarded|delete|deleted|deactivate|deactivated|disable|disabled|freeze|frozen|activate|activated|enable|enabled|unfreeze|onboard|hire|hired|join|reinstated)/', $eventText)
            && preg_match('/(user|employee|employment|staff|person)/', $eventText)
        ) {
            return true;
        }

        return self::payloadHasLifecycleStatus($payload);
    }

    private static function payloadHasLifecycleStatus($payload)
    {
        $user = self::eventUserPayload($payload);
        foreach (['status', 'employee_status', 'employment_status', 'account_status'] as $field) {
            if (isset($user[$field])) {
                return true;
            }
        }
        foreach (['active', 'is_active', 'enabled', 'is_enabled', 'is_activated', 'is_frozen', 'is_unjoin', 'is_resigned', 'is_exited', 'is_deleted'] as $field) {
            if (isset($user[$field])) {
                return true;
            }
        }
        return false;
    }

    private static function eventUserPayload($payload)
    {
        $event = $payload['event'] ?? $payload;
        foreach (['user', 'object', 'employee', 'employment'] as $field) {
            if (isset($event[$field]) && is_array($event[$field])) {
                return $event[$field];
            }
        }
        return is_array($event) ? $event : [];
    }

    private static function eventLifecycle($payload, $eventType)
    {
        $user = self::eventUserPayload($payload);
        $eventText = strtolower($eventType);

        if (self::eventMeansDeleted($payload, $eventType)) {
            return 'deleted';
        }
        if (preg_match('/deactivate|deactivated|disable|disabled|freeze|frozen/i', $eventText)) {
            return 'disabled';
        }
        if (preg_match('/created|create|enable|enabled|activate|activated|unfreeze|onboard|hire|hired|join|reinstated/i', $eventText)) {
            return 'active';
        }

        foreach (['status', 'employee_status', 'employment_status', 'account_status'] as $field) {
            if (isset($user[$field])) {
                return self::statusValueIsActive($user[$field]) ? 'active' : 'disabled';
            }
        }
        foreach (['active', 'is_active', 'enabled', 'is_enabled', 'is_activated'] as $field) {
            if (isset($user[$field])) {
                return ($user[$field] === true || $user[$field] === 'true' || $user[$field] === 1 || $user[$field] === '1') ? 'active' : 'disabled';
            }
        }
        foreach (['is_frozen', 'is_unjoin'] as $field) {
            if (isset($user[$field]) && ($user[$field] === true || $user[$field] === 'true' || $user[$field] === 1 || $user[$field] === '1')) {
                return 'disabled';
            }
        }
        return 'unknown';
    }

    private static function eventMeansDeleted($payload, $eventType)
    {
        $user = self::eventUserPayload($payload);
        $eventText = strtolower($eventType);

        if (preg_match('/deleted|delete|resign|resigned|resignation|exit|exited|terminate|terminated|offboard|offboarding|offboarded/i', $eventText)) {
            return true;
        }

        foreach (['status', 'employee_status', 'employment_status', 'account_status'] as $field) {
            if (isset($user[$field]) && self::statusValueMeansDeleted($user[$field])) {
                return true;
            }
        }
        return false;
    }

    private static function statusValueIsActive($status)
    {
        if (is_array($status)) {
            if (self::statusValueMeansDeleted($status)) {
                return false;
            }
            if (
                (isset($status['is_activated']) && $status['is_activated'] !== true) ||
                (isset($status['is_frozen']) && $status['is_frozen'] == true) ||
                (isset($status['is_unjoin']) && $status['is_unjoin'] == true)
            ) {
                return false;
            }
            return true;
        }

        $value = strtolower(trim((string)$status));
        if (self::statusValueMeansDeleted($value) || in_array($value, ['inactive', 'disabled', 'disable', 'deactivated', 'frozen', 'unjoin'], true)) {
            return false;
        }
        return true;
    }

    private static function statusValueMeansDeleted($status)
    {
        if (is_array($status)) {
            return
                (isset($status['is_resigned']) && $status['is_resigned'] == true) ||
                (isset($status['is_exited']) && $status['is_exited'] == true) ||
                (isset($status['is_deleted']) && $status['is_deleted'] == true);
        }

        $value = strtolower(trim((string)$status));
        return in_array($value, ['resigned', 'resign', 'deleted', 'delete', 'terminated', 'terminate', 'offboarded', 'offboarding', 'exited', 'exit'], true);
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
