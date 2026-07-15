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
                $this->addLearner($payload);
            break;
            default:
                $this->respond(404, ['ok' => false, 'message' => 'Undefined API method '.$method]);
        }
    }

    private function addLearner($payload)
    {
        $studentNo = $this->normalizeStudentNo($payload['student_no'] ?? ($payload['studentNo'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $remark = trim((string)($payload['remark'] ?? ''));
        $hasStatus = array_key_exists('status', $payload);
        $status = $hasStatus ? $this->normalizeStatus($payload['status']) : 'true';
        $cardId = '';

        if ($studentNo === '') {
            $this->respond(422, ['ok' => false, 'message' => 'student_no不能为空，且只能包含字母、数字、下划线和中划线']);
        }
        if ($name === '') {
            $this->respond(422, ['ok' => false, 'message' => 'name不能为空']);
        }
        if ($this->utf8Length($name) > 100 || $this->utf8Length($remark) > 200) {
            $this->respond(422, ['ok' => false, 'message' => 'name或remark过长']);
        }

        $hasCardId = array_key_exists('card_id', $payload) || array_key_exists('cardId', $payload);
        if ($hasCardId) {
            $cardId = AttendanceService::normalizeCardNumber($payload['card_id'] ?? ($payload['cardId'] ?? ''));
            if ($cardId !== '' && !preg_match('/^[0-9]{10}$/', $cardId)) {
                $this->respond(422, ['ok' => false, 'message' => 'card_id必须为空或10位数字']);
            }
            $this->assertCardAvailable($cardId, $studentNo);
        }

        $now = time();
        $existing = Database::querySingleLine('learner', ['student_no' => $studentNo]);
        $data = [
            'student_no' => $studentNo,
            'name' => $name,
            'remark' => $remark,
            'updated_at' => $now
        ];
        if ($hasStatus || !$existing) {
            $data['status'] = $status;
        }
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
            $data['created_at'] = $now;
            $result = Database::insert('learner', $data);
            $action = 'created';
            global $conn;
            $id = intval(mysqli_insert_id($conn));
        }

        if ($result !== true) {
            $this->respond(500, ['ok' => false, 'message' => '学员保存失败：'.$result]);
        }

        $responseStatus = ($hasStatus || !$existing) ? $status : ($existing['status'] ?? 'true');
        $responseCardId = $hasCardId ? $cardId : ($existing ? ($existing['card_id'] ?? '') : '');
        $this->respond($action === 'created' ? 201 : 200, [
            'ok' => true,
            'message' => $action === 'created' ? '学员已创建' : '学员已更新',
            'action' => $action,
            'learner' => [
                'id' => $id,
                'student_no' => $studentNo,
                'name' => $name,
                'status' => $responseStatus,
                'card_id' => $responseCardId,
                'remark' => $remark
            ]
        ]);
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

    private function assertCardAvailable($cardId, $studentNo)
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
        if ($learner && ($learner['student_no'] ?? '') !== $studentNo) {
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
