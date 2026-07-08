<?php

/*

飞书对接模块
Ver 1.1.0.0 20260708
Code by Jason / Codex

*/

namespace anim210System;

use anim210System;

class appLinkFeishu {
    private $_config;
    private $keyContent = [];
    private $keyFile;

    public function __construct($skipTenantToken = false) {
        global $_config;

        $this->_config = $_config;
        $this->keyFile = $_config['feishu']['keyConfigFile'] ?? (ROOT . '/feishu_key.json');
        $this->loadKeyFile();
        if (!$skipTenantToken) {
            $this->getTenantAccessToken();
        }
    }

    public function getFeishuMemberList() {
        $allDepartments = [];
        $allMembers = [];
        $processedOpenIds = [];

        $this->fetchDepartments($allDepartments);
        foreach ($allDepartments as $departmentId => $departmentName) {
            $this->fetchMembers($allMembers, $departmentId, $departmentName, $processedOpenIds);
        }
        return $allMembers;
    }

    public function verifyFeishuMemberStatus($open_id) {
        $token = $this->getTenantAccessToken();
        if (!$token) {
            return true;
        }
        $url = $this->_config['feishu']['appEndpoint']['getMemberInfo'].$open_id.'?department_id_type=open_department_id&user_id_type=open_id';

        $data = $this->requestFeishu($url, 'GET', $token, null, 2);
        if (!isset($data['response']['data']['user']['status'])) {
            return true;
        }
        return $this->isActiveStatus($data['response']['data']['user']['status']);
    }

    public function getTenantAccessToken() {
        $now = time();
        if (!empty($this->keyContent['tenant_access_token']) && !empty($this->keyContent['tenant_access_token_expires_at']) && intval($this->keyContent['tenant_access_token_expires_at']) > $now + 300) {
            return $this->keyContent['tenant_access_token'];
        }

        $appId = Settings::get('feishu_app_id', '');
        $appSecret = Settings::get('feishu_app_secret', '');
        if ($appId === '' || $appSecret === '') {
            return '';
        }

        $url = $this->_config['feishu']['appEndpoint']['getTenantToken'];
        $newTenantToken = $this->requestFeishu($url, 'POST', null, ['app_id' => $appId, 'app_secret' => $appSecret]);
        if (($newTenantToken['status_code'] ?? 0) != 200 || !isset($newTenantToken['response']['tenant_access_token'])) {
            return '';
        }

        $expiresIn = intval($newTenantToken['response']['expire'] ?? $newTenantToken['response']['expires_in'] ?? 7200);
        $this->keyContent['tenant_access_token'] = $newTenantToken['response']['tenant_access_token'];
        $this->keyContent['tenant_access_token_expires_at'] = $now + $expiresIn;
        $this->saveKeyFile();
        return $this->keyContent['tenant_access_token'];
    }

    public function getAppAccessToken() {
        $now = time();
        if (!empty($this->keyContent['app_access_token']) && !empty($this->keyContent['app_access_token_expires_at']) && intval($this->keyContent['app_access_token_expires_at']) > $now + 300) {
            return $this->keyContent['app_access_token'];
        }

        $appId = Settings::get('feishu_app_id', '');
        $appSecret = Settings::get('feishu_app_secret', '');
        if ($appId === '' || $appSecret === '') {
            return '';
        }

        $url = 'https://open.feishu.cn/open-apis/auth/v3/app_access_token/internal';
        $data = $this->requestFeishu($url, 'POST', null, ['app_id' => $appId, 'app_secret' => $appSecret]);
        if (($data['status_code'] ?? 0) != 200 || !isset($data['response']['app_access_token'])) {
            return '';
        }

        $expiresIn = intval($data['response']['expire'] ?? $data['response']['expires_in'] ?? 7200);
        $this->keyContent['app_access_token'] = $data['response']['app_access_token'];
        $this->keyContent['app_access_token_expires_at'] = $now + $expiresIn;
        $this->saveKeyFile();
        return $this->keyContent['app_access_token'];
    }

    public function getUserAccessToken($code) {
        $appToken = $this->getAppAccessToken();
        if ($appToken === '') {
            return ['ok' => false, 'message' => '无法获取 app_access_token'];
        }

        $data = $this->requestFeishu('https://open.feishu.cn/open-apis/authen/v1/access_token', 'POST', $appToken, [
            'grant_type' => 'authorization_code',
            'code' => $code
        ], 10);

        if (($data['status_code'] ?? 0) != 200 || intval($data['response']['code'] ?? -1) !== 0) {
            return ['ok' => false, 'message' => json_encode($data, JSON_UNESCAPED_UNICODE)];
        }
        return ['ok' => true, 'data' => $data['response']['data']];
    }

    public function getUserInfo($userAccessToken) {
        $data = $this->requestFeishu('https://open.feishu.cn/open-apis/authen/v1/user_info', 'GET', $userAccessToken, null, 10);
        if (($data['status_code'] ?? 0) != 200 || intval($data['response']['code'] ?? -1) !== 0) {
            return ['ok' => false, 'message' => json_encode($data, JSON_UNESCAPED_UNICODE)];
        }
        return ['ok' => true, 'data' => $data['response']['data']];
    }

    public function sendTextMessage($openId, $text, $uuid = '') {
        $tenantToken = $this->getTenantAccessToken();
        if ($tenantToken === '') {
            return ['ok' => false, 'message' => '无法获取 tenant_access_token'];
        }

        $body = [
            'receive_id' => $openId,
            'msg_type' => 'text',
            'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)
        ];
        if ($uuid !== '') {
            $body['uuid'] = substr($uuid, 0, 50);
        }

        $data = $this->requestFeishu('https://open.feishu.cn/open-apis/im/v1/messages?receive_id_type=open_id', 'POST', $tenantToken, $body, 10);
        if (($data['status_code'] ?? 0) >= 200 && ($data['status_code'] ?? 0) < 300 && intval($data['response']['code'] ?? -1) === 0) {
            return ['ok' => true, 'data' => $data['response']['data'] ?? []];
        }
        return ['ok' => false, 'message' => json_encode($data, JSON_UNESCAPED_UNICODE)];
    }

    private function fetchDepartments(&$allDepartments, $pageToken = null) {
        $token = $this->getTenantAccessToken();
        if ($token === '') {
            return;
        }

        $url = $this->_config['feishu']['appEndpoint']['getAllDepartments'];
        if ($pageToken) {
            $url .= '&page_token='.$pageToken;
        }
        $data = $this->requestFeishu($url, 'GET', $token, null);
        $departmentsData = $data['response'];

        if (isset($departmentsData['data']['items'])) {
            foreach ($departmentsData['data']['items'] as $item) {
                if (isset($item['open_department_id'])) {
                    $allDepartments[$item['open_department_id']] = $item['name'] ?? '';
                } elseif (isset($item['department_id'])) {
                    $allDepartments[$item['department_id']] = $item['name'] ?? '';
                }
            }
        }

        if (isset($departmentsData['data']['has_more']) && $departmentsData['data']['has_more'] === true && isset($departmentsData['data']['page_token'])) {
            $this->fetchDepartments($allDepartments, $departmentsData['data']['page_token']);
        }
    }

    private function fetchMembers(&$allMembers, $departmentId, $departmentName, &$processedOpenIds, $pageToken = null) {
        $token = $this->getTenantAccessToken();
        if ($token === '') {
            return;
        }

        $url = $this->_config['feishu']['appEndpoint']['getDepartmentMemberInfo'];
        $url .= $departmentId;
        if ($pageToken) {
            $url .= '&page_token='.$pageToken;
        }
        $data = $this->requestFeishu($url, 'GET', $token, null);
        $membersData = $data['response'];

        if (isset($membersData['data']['items'])) {
            foreach ($membersData['data']['items'] as $item) {
                if (!isset($item['open_id'])) {
                    continue;
                }
                if (in_array($item['open_id'], $processedOpenIds)) {
                    continue;
                }
                $processedOpenIds[] = $item['open_id'];
                $status = $this->isActiveStatus($item['status'] ?? []);
                $realName = $this->extractRealName($item);
                $departmentIds = $item['department_ids'] ?? [$departmentId];

                $allMembers[] = [
                    'open_id' => $item['open_id'],
                    'user_id' => $item['user_id'] ?? '',
                    'union_id' => $item['union_id'] ?? '',
                    'name' => $item['name'] ?? '',
                    'employee_no' => $item['employee_no'] ?? '',
                    'real_name' => $realName,
                    'status' => $status,
                    'department_id' => $departmentIds[0] ?? $departmentId,
                    'department_name' => $departmentName,
                    'department_ids' => $departmentIds,
                    'email' => $item['email'] ?? '',
                    'mobile' => $item['mobile'] ?? '',
                    'tenant_key' => $item['tenant_key'] ?? ''
                ];
            }
        }

        if (isset($membersData['data']['has_more']) && $membersData['data']['has_more'] === true && isset($membersData['data']['page_token'])) {
            $this->fetchMembers($allMembers, $departmentId, $departmentName, $processedOpenIds, $membersData['data']['page_token']);
        }
    }

    private function extractRealName($item) {
        $realName = '--';
        if (isset($item['custom_attrs']) && is_array($item['custom_attrs'])) {
            foreach ($item['custom_attrs'] as $attr) {
                if (
                    isset($attr['id']) &&
                    $attr['id'] === 'C-7077865918377885700' &&
                    isset($attr['value']['text']) &&
                    trim($attr['value']['text']) !== ''
                ) {
                    $realName = $attr['value']['text'];
                    break;
                }
            }
        }
        return $realName;
    }

    private function isActiveStatus($status) {
        if (!is_array($status) || count($status) === 0) {
            return true;
        }
        if (
            (isset($status['is_activated']) && $status['is_activated'] !== true) ||
            (isset($status['is_frozen']) && $status['is_frozen'] == true) ||
            (isset($status['is_resigned']) && $status['is_resigned'] == true) ||
            (isset($status['is_exited']) && $status['is_exited'] == true) ||
            (isset($status['is_unjoin']) && $status['is_unjoin'] == true)
        ) {
            return false;
        }
        return true;
    }

    public function requestFeishu($url, $method = 'GET', $authorization = '', $body = null, $timeout = 60) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));

        $headers = [];
        if (strtoupper($method) == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $jsonBody = json_encode($body ?: [], JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        if (!empty($authorization)) {
            $headers[] = 'Authorization: Bearer ' . $authorization;
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $curlError = curl_errno($ch) ? curl_error($ch) : '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status_code' => $httpCode,
            'response' => json_decode($response, true),
            'raw' => $response,
            'error' => $curlError
        ];
    }

    private function loadKeyFile() {
        if (!file_exists($this->keyFile)) {
            @file_put_contents($this->keyFile, "{}");
        }
        $keyFile = @file_get_contents($this->keyFile);
        $keyContent = json_decode($keyFile ?: '{}', true);
        $this->keyContent = json_last_error() === JSON_ERROR_NONE && is_array($keyContent) ? $keyContent : [];
    }

    private function saveKeyFile() {
        $fp = @fopen($this->keyFile, 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $current = stream_get_contents($fp);
            $currentData = json_decode($current ?: '{}', true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($currentData)) {
                $this->keyContent = array_merge($currentData, $this->keyContent);
            }
            $newJsonContent = json_encode($this->keyContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (json_last_error() === JSON_ERROR_NONE) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $newJsonContent);
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}
