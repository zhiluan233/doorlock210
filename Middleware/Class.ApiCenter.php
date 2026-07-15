<?php
/*

开放API中心模块
Ver 1.0.0.0 20260715
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

class ApiCenter {

    public function handle($params)
    {
        Header("Content-Type: application/json; charset=utf-8");

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, ['ok' => false, 'message' => 'API中心仅支持POST请求']);
        }
        if (!$this->isAuthorized()) {
            $this->respond(403, ['ok' => false, 'message' => 'API Key不正确或未配置']);
        }

        $method = $params['method'] ?? '';
        if (!preg_match('/^[A-Za-z0-9\_\-]{1,40}$/', $method)) {
            $this->respond(400, ['ok' => false, 'message' => 'API method不合法']);
        }

        $payload = $this->readJsonBody();
        switch ($method) {
            case 'addLearner':
            case 'createLearner':
                $this->saveLearner($payload, 'upsert');
            break;
            case 'updateLearner':
                $this->saveLearner($payload, 'update');
            break;
            case 'getLearner':
                $this->getLearner($payload);
            break;
            case 'listLearners':
                $this->listLearners($payload);
            break;
            case 'deleteLearner':
                $this->deleteLearner($payload);
            break;
            default:
                $this->respond(404, ['ok' => false, 'message' => 'Undefined API method '.$method]);
        }
    }

    private function saveLearner($payload, $mode)
    {
        $mode = $mode === 'update' ? 'update' : 'upsert';
        $id = intval($payload['id'] ?? 0);
        $studentNo = $this->normalizeStudentNo($payload['student_no'] ?? ($payload['studentNo'] ?? ''));
        $existing = null;
        if ($mode === 'update') {
            $existing = $this->findLearner($payload);
            if (!$existing) {
                $this->respond(404, ['ok' => false, 'message' => '学员不存在']);
            }
            $studentNo = $existing['student_no'] ?? '';
            $id = intval($existing['id']);
        } else {
            if ($studentNo === '') {
                $this->respond(422, ['ok' => false, 'message' => 'student_no不能为空，且只能包含字母、数字、下划线和中划线']);
            }
            $existing = Database::querySingleLine('learner', ['student_no' => $studentNo]);
            if ($existing) {
                $id = intval($existing['id']);
            }
        }

        $hasName = array_key_exists('name', $payload);
        $hasRealname = array_key_exists('realname', $payload) || array_key_exists('real_name', $payload);
        $hasMobile = array_key_exists('mobile', $payload);
        $hasClassName = array_key_exists('class_name', $payload) || array_key_exists('className', $payload);
        $hasTrainingCenter = array_key_exists('training_center', $payload) || array_key_exists('trainingCenter', $payload);
        $hasEnrolledAt = array_key_exists('enrolled_at', $payload) || array_key_exists('enrolledAt', $payload) || array_key_exists('enrolled_date', $payload) || array_key_exists('enrolledDate', $payload);
        $hasRemark = array_key_exists('remark', $payload);
        $hasStatus = array_key_exists('status', $payload);
        $hasCardId = array_key_exists('card_id', $payload) || array_key_exists('cardId', $payload);

        $name = $hasName ? trim((string)$payload['name']) : ($existing['name'] ?? '');
        $realname = $hasRealname ? trim((string)($payload['realname'] ?? ($payload['real_name'] ?? ''))) : ($existing['realname'] ?? '');
        $mobile = $hasMobile ? trim((string)$payload['mobile']) : ($existing['mobile'] ?? '');
        $className = $hasClassName ? trim((string)($payload['class_name'] ?? ($payload['className'] ?? ''))) : ($existing['class_name'] ?? '');
        $trainingCenter = $hasTrainingCenter ? trim((string)($payload['training_center'] ?? ($payload['trainingCenter'] ?? ''))) : ($existing['training_center'] ?? '');
        $enrolledAt = $hasEnrolledAt ? $this->normalizeDateValue($payload['enrolled_at'] ?? ($payload['enrolledAt'] ?? ($payload['enrolled_date'] ?? ($payload['enrolledDate'] ?? ''))), 'enrolled_at') : intval($existing['enrolled_at'] ?? 0);
        $remark = $hasRemark ? trim((string)$payload['remark']) : ($existing['remark'] ?? '');
        $status = $hasStatus ? $this->normalizeStatus($payload['status']) : ($existing['status'] ?? 'true');
        $cardId = $hasCardId ? AttendanceService::normalizeCardNumber($payload['card_id'] ?? ($payload['cardId'] ?? '')) : ($existing['card_id'] ?? '');

        if ($name === '') {
            $this->respond(422, ['ok' => false, 'message' => 'name（花名）不能为空']);
        }
        if ($realname === '') {
            $this->respond(422, ['ok' => false, 'message' => 'realname（真实姓名）不能为空']);
        }
        $this->validateLearnerText($name, $realname, $mobile, $className, $trainingCenter, $remark);
        if ($hasCardId && $cardId !== '' && !preg_match('/^[0-9]{10}$/', $cardId)) {
            $this->respond(422, ['ok' => false, 'message' => 'card_id必须为空或10位数字']);
        }
        $this->assertCardAvailable($cardId, $studentNo, $id);

        $now = time();
        $data = ['updated_at' => $now];
        if ($mode !== 'update') { $data['student_no'] = $studentNo; }
        if ($hasName || !$existing) { $data['name'] = $name; }
        if ($hasRealname || !$existing) { $data['realname'] = $realname; }
        if ($hasMobile || !$existing) { $data['mobile'] = $mobile; }
        if ($hasClassName || !$existing) { $data['class_name'] = $className; }
        if ($hasTrainingCenter || !$existing) { $data['training_center'] = $trainingCenter; }
        if ($hasEnrolledAt || !$existing) { $data['enrolled_at'] = $enrolledAt > 0 ? $enrolledAt : strtotime(date('Y-m-d')); }
        if ($hasRemark || !$existing) { $data['remark'] = $remark; }
        if ($hasStatus || !$existing) { $data['status'] = $status; }
        if ($hasCardId) {
            $data['card_id'] = $cardId;
        }

        if ($existing) {
            $result = Database::update('learner', $data, ['id' => $existing['id']]);
            $action = 'updated';
            $id = intval($existing['id']);
        } else {
            $data['id'] = null;
            if (!$hasCardId) {
                $data['card_id'] = '';
            }
            if (!isset($data['mobile'])) {
                $data['mobile'] = '';
            }
            if (!isset($data['remark'])) {
                $data['remark'] = '';
            }
            $data['created_at'] = $now;
            $result = Database::insert('learner', $data);
            $action = 'created';
            global $conn;
            $id = intval(mysqli_insert_id($conn));
        }

        if ($result !== true) {
            $this->respond(500, ['ok' => false, 'message' => '学员保存失败：'.$result]);
        }

        $learner = Database::querySingleLine('learner', ['id' => $id]);
        $this->respond($action === 'created' ? 201 : 200, [
            'ok' => true,
            'message' => $action === 'created' ? '学员已创建' : '学员已更新',
            'action' => $action,
            'learner' => $this->learnerPayload($learner ?: [])
        ]);
    }

    private function getLearner($payload)
    {
        $learner = $this->findLearner($payload);
        if (!$learner) {
            $this->respond(404, ['ok' => false, 'message' => '学员不存在']);
        }
        $this->respond(200, ['ok' => true, 'learner' => $this->learnerPayload($learner)]);
    }

    private function listLearners($payload)
    {
        global $conn;

        $page = max(1, intval($payload['page'] ?? 1));
        $pageSize = min(100, max(1, intval($payload['page_size'] ?? ($payload['pageSize'] ?? 20))));
        $offset = ($page - 1) * $pageSize;
        $where = ['1=1'];

        $status = $payload['status'] ?? '';
        if ($status !== '') {
            $where[] = "`status`='" . mysqli_real_escape_string($conn, $this->normalizeStatus($status)) . "'";
        }

        $keyword = trim((string)($payload['q'] ?? ($payload['keyword'] ?? '')));
        if ($keyword !== '') {
            $safe = mysqli_real_escape_string($conn, $keyword);
            $like = "'%{$safe}%'";
            $where[] = "(`student_no` LIKE {$like} OR `name` LIKE {$like} OR `realname` LIKE {$like} OR `mobile` LIKE {$like} OR `class_name` LIKE {$like} OR `training_center` LIKE {$like} OR `card_id` LIKE {$like})";
        }

        $whereSql = implode(' AND ', $where);
        $countRow = Database::querySingleLine('learner', "SELECT COUNT(*) AS `total` FROM `learner` WHERE {$whereSql}", true);
        $total = intval($countRow['total'] ?? 0);
        $rs = Database::query('learner', "SELECT * FROM `learner` WHERE {$whereSql} ORDER BY `id` DESC LIMIT {$offset}, {$pageSize}", '', true);
        $items = [];
        if ($rs && $rs instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($rs)) {
                $items[] = $this->learnerPayload($row);
            }
        }

        $this->respond(200, [
            'ok' => true,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'items' => $items
        ]);
    }

    private function deleteLearner($payload)
    {
        $learner = $this->findLearner($payload);
        if (!$learner) {
            $this->respond(404, ['ok' => false, 'message' => '学员不存在']);
        }
        $studentNo = $learner['student_no'] ?? '';
        if ($studentNo !== '') {
            Database::delete('access_role_members', [
                'member_kind' => 'learner',
                'employee_open_id' => $studentNo
            ]);
            Database::delete('access_policies', [
                'subject_kind' => 'learner',
                'subject_type' => 'learner',
                'subject_value' => $studentNo
            ]);
        }
        $result = Database::delete('learner', ['id' => $learner['id']]);
        if ($result !== true) {
            $this->respond(500, ['ok' => false, 'message' => '学员删除失败：'.$result]);
        }
        $this->respond(200, [
            'ok' => true,
            'message' => '学员已删除',
            'learner' => $this->learnerPayload($learner)
        ]);
    }

    private function findLearner($payload)
    {
        $id = intval($payload['id'] ?? 0);
        if ($id > 0) {
            return Database::querySingleLine('learner', ['id' => $id]);
        }

        $studentNo = $this->normalizeStudentNo($payload['student_no'] ?? ($payload['studentNo'] ?? ''));
        if ($studentNo === '') {
            return null;
        }
        return Database::querySingleLine('learner', ['student_no' => $studentNo]);
    }

    private function learnerPayload($learner)
    {
        return [
            'id' => intval($learner['id'] ?? 0),
            'student_no' => (string)($learner['student_no'] ?? ''),
            'name' => (string)($learner['name'] ?? ''),
            'realname' => (string)($learner['realname'] ?? ''),
            'mobile' => (string)($learner['mobile'] ?? ''),
            'class_name' => (string)($learner['class_name'] ?? ''),
            'training_center' => (string)($learner['training_center'] ?? ''),
            'enrolled_at' => intval($learner['enrolled_at'] ?? 0),
            'enrolled_date' => intval($learner['enrolled_at'] ?? 0) > 0 ? date('Y-m-d', intval($learner['enrolled_at'])) : '',
            'status' => (string)($learner['status'] ?? ''),
            'card_id' => (string)($learner['card_id'] ?? ''),
            'remark' => (string)($learner['remark'] ?? ''),
            'created_at' => intval($learner['created_at'] ?? 0),
            'updated_at' => intval($learner['updated_at'] ?? 0)
        ];
    }

    private function validateLearnerText($name, $realname, $mobile, $className, $trainingCenter, $remark)
    {
        if ($this->utf8Length($name) > 100 || $this->utf8Length($realname) > 100 || $this->utf8Length($className) > 100 || $this->utf8Length($trainingCenter) > 100 || $this->utf8Length($remark) > 200) {
            $this->respond(422, ['ok' => false, 'message' => 'name、realname、class_name、training_center或remark过长']);
        }
        if ($mobile !== '' && (!preg_match('/^[0-9\+\-\s]{1,32}$/', $mobile) || strlen($mobile) > 32)) {
            $this->respond(422, ['ok' => false, 'message' => 'mobile格式不合法']);
        }
    }

    private function isAuthorized()
    {
        $keys = $this->configuredKeys();
        if (count($keys) === 0) {
            return false;
        }
        $requestKey = $this->requestApiKey();
        return $requestKey !== '' && in_array($requestKey, $keys, true);
    }

    private function configuredKeys()
    {
        global $_config;

        $keys = $_config['apiCenter']['keys'] ?? ($_config['apiCenter']['apiKeys'] ?? []);
        if (is_string($keys)) {
            $keys = [$keys];
        }
        if (!is_array($keys)) {
            return [];
        }
        $normalized = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function requestApiKey()
    {
        $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        return trim((string)($_GET['key'] ?? ''));
    }

    private function readJsonBody()
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            $this->respond(400, ['ok' => false, 'message' => '请求体必须是JSON对象']);
        }
        return $payload;
    }

    private function assertCardAvailable($cardId, $studentNo, $learnerId = 0)
    {
        if ($cardId === '') {
            return;
        }

        $employee = Database::querySingleLine('employee', ['card_id' => $cardId]);
        if ($employee) {
            $this->respond(409, ['ok' => false, 'message' => '卡已经发给了员工 '.$employee['name']]);
        }
        $guest = Database::querySingleLine('guest', ['card_id' => $cardId]);
        if ($guest) {
            $this->respond(409, ['ok' => false, 'message' => '卡已经发给了访客 '.$guest['name']]);
        }
        $learner = Database::querySingleLine('learner', ['card_id' => $cardId]);
        if ($learner && intval($learner['id'] ?? 0) !== intval($learnerId) && ($learner['student_no'] ?? '') !== $studentNo) {
            $this->respond(409, ['ok' => false, 'message' => '卡已经发给了学员 '.$learner['name']]);
        }
    }

    private function normalizeStudentNo($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^[A-Za-z0-9\_\-]{1,64}$/', $value)) {
            return '';
        }
        return $value;
    }

    private function normalizeStatus($value)
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'enabled') {
            return 'true';
        }
        return 'false';
    }

    private function normalizeDateValue($value, $field)
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (is_numeric($value)) {
            $timestamp = intval($value);
            if ($timestamp > 100000000000) {
                $timestamp = intval($timestamp / 1000);
            }
            if ($timestamp < 0) {
                $this->respond(422, ['ok' => false, 'message' => $field.'必须是有效日期或时间戳']);
            }
            return $timestamp;
        }
        $text = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            $this->respond(422, ['ok' => false, 'message' => $field.'必须是YYYY-MM-DD或Unix时间戳']);
        }
        $parts = explode('-', $text);
        if (!checkdate(intval($parts[1]), intval($parts[2]), intval($parts[0]))) {
            $this->respond(422, ['ok' => false, 'message' => $field.'不是有效日期']);
        }
        return strtotime($text.' 00:00:00');
    }

    private function utf8Length($value)
    {
        if (preg_match_all('/./u', $value, $matches) === false) {
            return strlen($value);
        }
        return count($matches[0]);
    }

    private function respond($status, $data)
    {
        http_response_code($status);
        exit(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
