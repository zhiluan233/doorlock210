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
    private $lastError = '';
    private $departments = [];
    private $profileSupplementAvailable = true;

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
        $this->lastError = '';
        $this->departments = [];
        $allDepartments = [];
        $allMembers = [];
        $processedOpenIds = [];

        $this->fetchDepartments($allDepartments);
        if ($this->lastError !== '') {
            return [];
        }
        foreach ($allDepartments as $department) {
            $this->fetchMembers($allMembers, $department, $processedOpenIds);
            if ($this->lastError !== '') {
                return [];
            }
        }
        return $allMembers;
    }

    public function getLastDepartments() {
        return array_values($this->departments);
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function verifyFeishuMemberStatus($open_id) {
        $token = $this->getTenantAccessToken();
        if (!$token) {
            return true;
        }
        $url = $this->memberProfileUrl($this->_config['feishu']['appEndpoint']['getMemberInfo'] ?? '', $open_id);
        if ($url === '') {
            return true;
        }

        $data = $this->requestFeishu($url, 'GET', $token, null, 2);
        $user = $this->extractMemberProfileFromResponse($data['response'] ?? [], $open_id);
        if (!isset($user['status'])) {
            return true;
        }
        return $this->isActiveStatus($user['status']);
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

        $url = $this->endpoint('getAppToken');
        if ($url === '') {
            return '';
        }
        $data = $this->requestFeishu($url, 'POST', null, ['app_id' => $appId, 'app_secret' => $appSecret], 10, 2);
        if (($data['status_code'] ?? 0) != 200 || !isset($data['response']['app_access_token'])) {
            return '';
        }

        $expiresIn = intval($data['response']['expire'] ?? $data['response']['expires_in'] ?? 7200);
        $this->keyContent['app_access_token'] = $data['response']['app_access_token'];
        $this->keyContent['app_access_token_expires_at'] = $now + $expiresIn;
        $this->saveKeyFile();
        return $this->keyContent['app_access_token'];
    }

    public function getUserAccessToken($code, $redirectUri = '') {
        $appId = Settings::get('feishu_app_id', '');
        $appSecret = Settings::get('feishu_app_secret', '');
        if ($appId === '' || $appSecret === '') {
            return ['ok' => false, 'message' => '飞书 App ID 或 App Secret 未在 config.php 中配置'];
        }

        $url = $this->endpoint('getUserAccessTokenV3');
        if ($url === '') {
            return ['ok' => false, 'message' => '飞书 getUserAccessTokenV3 endpoint 未在 config.php 中配置'];
        }

        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'code' => $code
        ];
        if ($redirectUri !== '') {
            $body['redirect_uri'] = $redirectUri;
        }

        $data = $this->requestFeishu($url, 'POST', null, $body, 8, 1);

        if (($data['status_code'] ?? 0) != 200 || intval($data['response']['code'] ?? -1) !== 0) {
            return ['ok' => false, 'message' => json_encode($data, JSON_UNESCAPED_UNICODE)];
        }
        $tokenData = $data['response']['data'] ?? $data['response'];
        return ['ok' => true, 'data' => is_array($tokenData) ? $tokenData : []];
    }

    public function getUserInfo($userAccessToken) {
        $url = $this->endpoint('getUserInfo');
        if ($url === '') {
            return ['ok' => false, 'message' => '飞书 getUserInfo endpoint 未在 config.php 中配置'];
        }

        $data = $this->requestFeishu($url, 'GET', $userAccessToken, null, 8, 1);
        if (($data['status_code'] ?? 0) != 200 || intval($data['response']['code'] ?? -1) !== 0) {
            return ['ok' => false, 'message' => json_encode($data, JSON_UNESCAPED_UNICODE)];
        }
        return ['ok' => true, 'data' => $data['response']['data']];
    }

    public function getJsSdkConfig($url, $jsApiList = []) {
        $appId = Settings::get('feishu_app_id', '');
        if ($appId === '') {
            return ['ok' => false, 'message' => '飞书 App ID 未在 config.php 中配置'];
        }

        $url = trim((string)$url);
        $url = explode('#', $url, 2)[0];
        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            return ['ok' => false, 'message' => 'JSAPI 签名 URL 不合法'];
        }

        $ticket = $this->getJsSdkTicket();
        if ($ticket === '') {
            return ['ok' => false, 'message' => $this->lastError ?: '无法获取飞书 JSAPI ticket'];
        }

        try {
            $nonce = bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            $nonce = md5(uniqid('', true));
        }
        $timestamp = time() * 1000;
        $signature = sha1('jsapi_ticket='.$ticket.'&noncestr='.$nonce.'&timestamp='.$timestamp.'&url='.$url);

        return [
            'ok' => true,
            'data' => [
                'appId' => $appId,
                'timestamp' => $timestamp,
                'nonceStr' => $nonce,
                'signature' => $signature,
                'jsApiList' => is_array($jsApiList) ? array_values($jsApiList) : []
            ]
        ];
    }

    public function sendInteractiveMessage($openId, $card, $uuid = '') {
        $tenantToken = $this->getTenantAccessToken();
        if ($tenantToken === '') {
            return ['ok' => false, 'message' => '无法获取 tenant_access_token'];
        }
        $url = $this->endpoint('sendMessage');
        if ($url === '') {
            return ['ok' => false, 'message' => '飞书 sendMessage endpoint 未在 config.php 中配置'];
        }

        $body = [
            'receive_id' => $openId,
            'msg_type' => 'interactive',
            'content' => json_encode($card, JSON_UNESCAPED_UNICODE)
        ];
        if ($uuid !== '') {
            $body['uuid'] = substr($uuid, 0, 50);
        }

        $data = $this->requestFeishu($url, 'POST', $tenantToken, $body, 10);
        if (($data['status_code'] ?? 0) >= 200 && ($data['status_code'] ?? 0) < 300 && intval($data['response']['code'] ?? -1) === 0) {
            return ['ok' => true, 'data' => $data['response']['data'] ?? []];
        }
        return ['ok' => false, 'message' => json_encode($data, JSON_UNESCAPED_UNICODE)];
    }

    public function sendTextMessage($openId, $text, $uuid = '') {
        $tenantToken = $this->getTenantAccessToken();
        if ($tenantToken === '') {
            return ['ok' => false, 'message' => '无法获取 tenant_access_token'];
        }
        $url = $this->endpoint('sendMessage');
        if ($url === '') {
            return ['ok' => false, 'message' => '飞书 sendMessage endpoint 未在 config.php 中配置'];
        }

        $body = [
            'receive_id' => $openId,
            'msg_type' => 'text',
            'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)
        ];
        if ($uuid !== '') {
            $body['uuid'] = substr($uuid, 0, 50);
        }

        $data = $this->requestFeishu($url, 'POST', $tenantToken, $body, 10);
        if (($data['status_code'] ?? 0) >= 200 && ($data['status_code'] ?? 0) < 300 && intval($data['response']['code'] ?? -1) === 0) {
            return ['ok' => true, 'data' => $data['response']['data'] ?? []];
        }
        return ['ok' => false, 'message' => json_encode($data, JSON_UNESCAPED_UNICODE)];
    }

    private function fetchDepartments(&$allDepartments, $pageToken = null) {
        $token = $this->getTenantAccessToken();
        if ($token === '') {
            $this->lastError = '无法获取 tenant_access_token';
            return;
        }

        $url = $this->_config['feishu']['appEndpoint']['getAllDepartments'];
        if ($pageToken) {
            $url .= '&page_token='.$pageToken;
        }
        $data = $this->requestFeishu($url, 'GET', $token, null);
        if (($data['status_code'] ?? 0) < 200 || ($data['status_code'] ?? 0) >= 300 || !isset($data['response']['data'])) {
            $this->lastError = '拉取飞书部门失败：' . json_encode($data, JSON_UNESCAPED_UNICODE);
            return;
        }
        $departmentsData = $data['response'];

        if (isset($departmentsData['data']['items'])) {
            foreach ($departmentsData['data']['items'] as $item) {
                $department = $this->normalizeDepartment($item);
                if (!$department) {
                    continue;
                }
                $this->departments[$department['department_id']] = $department;
                $allDepartments[$department['department_id']] = $department;
            }
        }

        if (isset($departmentsData['data']['has_more']) && $departmentsData['data']['has_more'] === true && isset($departmentsData['data']['page_token'])) {
            $this->fetchDepartments($allDepartments, $departmentsData['data']['page_token']);
        }
    }

    private function normalizeDepartment($item) {
        $departmentId = $item['department_id'] ?? ($item['open_department_id'] ?? '');
        $departmentId = trim((string)$departmentId);
        if ($departmentId === '') {
            return null;
        }

        $name = trim((string)($item['name'] ?? ''));
        if ($name === '' && isset($item['i18n_name']) && is_array($item['i18n_name'])) {
            $name = $item['i18n_name']['zh_cn'] ?? ($item['i18n_name']['en_us'] ?? '');
        }
        if ($name === '') {
            $name = $departmentId;
        }

        $leaderUserId = $item['leader_user_id'] ?? ($item['leader_open_id'] ?? '');
        if (is_array($leaderUserId)) {
            $leaderUserId = $leaderUserId[0] ?? '';
        }

        return [
            'department_id' => $departmentId,
            'open_department_id' => trim((string)($item['open_department_id'] ?? '')),
            'parent_department_id' => trim((string)($item['parent_department_id'] ?? ($item['parent_open_department_id'] ?? ''))),
            'name' => $name,
            'i18n_name' => $item['i18n_name'] ?? [],
            'leader_user_id' => trim((string)$leaderUserId),
            'member_count' => intval($item['member_count'] ?? 0),
            'raw_payload' => $item
        ];
    }

    private function fetchMembers(&$allMembers, $department, &$processedOpenIds, $pageToken = null) {
        $token = $this->getTenantAccessToken();
        if ($token === '') {
            $this->lastError = '无法获取 tenant_access_token';
            return;
        }

        $endpoint = $this->_config['feishu']['appEndpoint']['getDepartmentMemberInfo'];
        $memberDepartmentIdType = $this->urlQueryParam($endpoint, 'department_id_type');
        if ($memberDepartmentIdType === '') {
            $memberDepartmentIdType = $this->urlQueryParam($this->_config['feishu']['appEndpoint']['getAllDepartments'] ?? '', 'department_id_type');
        }
        if ($memberDepartmentIdType === '') {
            $memberDepartmentIdType = 'open_department_id';
        }

        $departmentId = $this->departmentIdForType($department, $memberDepartmentIdType);
        $departmentName = is_array($department) ? ($department['name'] ?? $departmentId) : $departmentId;
        $canonicalDepartmentId = is_array($department) ? ($department['department_id'] ?? $departmentId) : $departmentId;
        if ($departmentId === '') {
            return;
        }

        $url = $endpoint . rawurlencode($departmentId);
        if ($this->urlQueryParam($url, 'department_id_type') === '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'department_id_type=' . rawurlencode($memberDepartmentIdType);
        }
        if ($pageToken) {
            $url .= '&page_token='.rawurlencode($pageToken);
        }
        $data = $this->requestFeishu($url, 'GET', $token, null);
        if (($data['status_code'] ?? 0) < 200 || ($data['status_code'] ?? 0) >= 300 || !isset($data['response']['data'])) {
            $this->lastError = '拉取飞书部门成员失败：' . json_encode($data, JSON_UNESCAPED_UNICODE);
            return;
        }
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
                $statusDetail = $item['status'] ?? [];
                $lifecycle = $this->statusLifecycle($statusDetail);
                $status = $lifecycle === 'active';
                $realName = $this->extractRealName($item);
                $jobTitle = $this->extractJobTitle($item);
                $joinedAt = $this->extractJoinedAt($item);
                if ($jobTitle === '' || $joinedAt <= 0) {
                    $profile = $this->fetchMemberProfile($item['open_id']);
                    if (is_array($profile)) {
                        if ($jobTitle === '') {
                            $jobTitle = $this->extractJobTitle($profile);
                        }
                        if ($joinedAt <= 0) {
                            $joinedAt = $this->extractJoinedAt($profile);
                        }
                    }
                }
                $departmentIds = $item['department_ids'] ?? [$canonicalDepartmentId];

                $allMembers[] = [
                    'open_id' => $item['open_id'],
                    'user_id' => $item['user_id'] ?? '',
                    'union_id' => $item['union_id'] ?? '',
                    'name' => $item['name'] ?? '',
                    'employee_no' => $item['employee_no'] ?? '',
                    'real_name' => $realName,
                    'status' => $status,
                    'status_detail' => $statusDetail,
                    'lifecycle' => $lifecycle,
                    'department_id' => $departmentIds[0] ?? $departmentId,
                    'department_name' => $departmentName,
                    'department_ids' => $departmentIds,
                    'email' => $item['email'] ?? '',
                    'mobile' => $item['mobile'] ?? '',
                    'tenant_key' => $item['tenant_key'] ?? '',
                    'avatar_url' => $this->extractAvatarUrl($item),
                    'job_title' => $jobTitle,
                    'joined_at' => $joinedAt
                ];
            }
        }

        if (isset($membersData['data']['has_more']) && $membersData['data']['has_more'] === true && isset($membersData['data']['page_token'])) {
            $this->fetchMembers($allMembers, $department, $processedOpenIds, $membersData['data']['page_token']);
        }
    }

    private function fetchMemberProfile($openId) {
        $openId = trim((string)$openId);
        if ($openId === '' || !$this->profileSupplementAvailable) {
            return null;
        }
        $token = $this->getTenantAccessToken();
        $endpoint = $this->_config['feishu']['appEndpoint']['getMemberInfo'] ?? '';
        if ($token === '' || $endpoint === '') {
            return null;
        }

        $url = $this->memberProfileUrl($endpoint, $openId);
        if ($url === '') {
            return null;
        }
        $data = $this->requestFeishu($url, 'GET', $token, null, 5, 1);
        if (($data['status_code'] ?? 0) < 200 || ($data['status_code'] ?? 0) >= 300) {
            $statusCode = intval($data['status_code'] ?? 0);
            if ($statusCode === 0 || $statusCode === 400 || $statusCode === 401 || $statusCode === 403 || ($data['error'] ?? '') !== '') {
                $this->profileSupplementAvailable = false;
            }
            return null;
        }
        $user = $this->extractMemberProfileFromResponse($data['response'] ?? [], $openId);
        return is_array($user) ? $user : null;
    }

    private function memberProfileUrl($endpoint, $openId) {
        $endpoint = trim((string)$endpoint);
        $openId = trim((string)$openId);
        if ($endpoint === '' || $openId === '') {
            return '';
        }

        foreach (['{open_id}', '{user_id}', '{id}'] as $placeholder) {
            if (strpos($endpoint, $placeholder) !== false) {
                $url = str_replace($placeholder, rawurlencode($openId), $endpoint);
                return $this->appendMemberProfileQuery($url);
            }
        }

        $path = (string)parse_url($endpoint, PHP_URL_PATH);
        if (strpos($endpoint, '?') === false) {
            if (preg_match('/\/(?:batch|batch_get)\/?$/', $path)) {
                $url = $endpoint . '?user_ids=' . rawurlencode($openId);
            } else {
                $url = rtrim($endpoint, '/') . '/' . rawurlencode($openId);
            }
        } else if (preg_match('/(?:^|[?&])(?:user_ids|user_id|open_id)=$/', $endpoint) || preg_match('/[=\/]$/', $endpoint)) {
            $url = $endpoint . rawurlencode($openId);
        } else if (preg_match('/[?&]$/', $endpoint)) {
            $url = $endpoint . 'user_ids=' . rawurlencode($openId);
        } else if ($this->urlQueryParam($endpoint, 'user_ids') === '' && $this->urlQueryParam($endpoint, 'user_id') === '' && $this->urlQueryParam($endpoint, 'open_id') === '') {
            $url = $endpoint . '&user_ids=' . rawurlencode($openId);
        } else {
            $url = $endpoint;
        }

        return $this->appendMemberProfileQuery($url);
    }

    private function appendMemberProfileQuery($url) {
        if ($url === '') {
            return '';
        }
        if ($this->urlQueryParam($url, 'department_id_type') === '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'department_id_type=department_id';
        }
        if ($this->urlQueryParam($url, 'user_id_type') === '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'user_id_type=open_id';
        }
        return $url;
    }

    private function extractMemberProfileFromResponse($response, $openId = '') {
        if (!is_array($response)) {
            return null;
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        if (isset($data['user']) && is_array($data['user'])) {
            return $data['user'];
        }
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($openId === '' || ($item['open_id'] ?? '') === $openId || ($item['user_id'] ?? '') === $openId) {
                    return $item;
                }
            }
            foreach ($data['items'] as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }
        if (isset($data['open_id']) || isset($data['user_id']) || isset($data['job_title']) || isset($data['join_time'])) {
            return $data;
        }
        return null;
    }

    private function departmentIdForType($department, $departmentIdType) {
        if (!is_array($department)) {
            return (string)$department;
        }
        if ($departmentIdType === 'open_department_id' && !empty($department['open_department_id'])) {
            return $department['open_department_id'];
        }
        if ($departmentIdType === 'department_id' && !empty($department['department_id'])) {
            return $department['department_id'];
        }
        if (!empty($department['department_id'])) {
            return $department['department_id'];
        }
        return $department['open_department_id'] ?? '';
    }

    private function urlQueryParam($url, $key) {
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query === null || $query === false || $query === '') {
            return '';
        }
        $params = [];
        parse_str($query, $params);
        return isset($params[$key]) ? (string)$params[$key] : '';
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

    private function extractAvatarUrl($item) {
        if (isset($item['avatar']) && is_array($item['avatar'])) {
            foreach (['avatar_origin', 'avatar_640', 'avatar_240', 'avatar_72', 'avatar_url'] as $key) {
                if (!empty($item['avatar'][$key])) {
                    return (string)$item['avatar'][$key];
                }
            }
        }
        foreach (['avatar_url', 'avatar_thumb', 'avatar_middle', 'avatar_big'] as $key) {
            if (!empty($item[$key])) {
                return (string)$item[$key];
            }
        }
        return '';
    }

    private function extractJobTitle($item) {
        $value = $this->extractFieldByConfig($item, 'employeePositionFields', 'jobTitle', ['job_title', 'jobTitle', 'position', 'title', 'employee_title', 'staff_title', 'work_title']);
        return $this->limitText($value, 100);
    }

    private function extractJoinedAt($item) {
        $value = $this->extractFieldByConfig($item, 'employeeJoinedAtFields', 'joinedAt', ['join_time', 'joinTime', 'joined_at', 'joinedAt', 'hire_date', 'hireDate', 'entry_time', 'entryTime', 'employment_time', 'employmentTime', 'onboard_time', 'onboardTime', 'start_time', 'startTime']);
        return $this->parseDateTimeValue($value);
    }

    private function extractFieldByConfig($item, $fieldKey, $customKey, $defaults) {
        $profile = $this->_config['feishu']['badgeLookup']['profile'] ?? [];
        $configuredFields = $profile[$fieldKey] ?? [];
        if (!is_array($configuredFields)) {
            $configuredFields = [];
        }
        $fields = array_values(array_unique(array_merge($defaults, $configuredFields)));
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if ($field === '') {
                continue;
            }
            $value = $this->fieldValueByPath($item, $field);
            $text = $this->customValueText($value);
            if ($text !== '') {
                return $text;
            }
        }

        $defaultMap = $this->defaultCustomAttrMap($customKey);
        $customAttrMap = is_array($profile['customAttrMap'] ?? null) ? $profile['customAttrMap'] : [];
        $configuredMap = $customAttrMap[$customKey] ?? [];
        if (!is_array($configuredMap)) {
            $configuredMap = [];
        }
        $map = [
            'ids' => array_values(array_unique(array_merge(
                $defaultMap['ids'],
                isset($configuredMap['ids']) && is_array($configuredMap['ids']) ? array_map('strval', $configuredMap['ids']) : []
            ))),
            'names' => array_values(array_unique(array_merge(
                $defaultMap['names'],
                isset($configuredMap['names']) && is_array($configuredMap['names']) ? array_map('strval', $configuredMap['names']) : []
            )))
        ];
        return $this->extractCustomAttrValue($item, $map);
    }

    private function fieldValueByPath($item, $field) {
        if (!is_array($item) || $field === '') {
            return null;
        }
        if (array_key_exists($field, $item)) {
            return $item[$field];
        }
        if (strpos($field, '.') !== false) {
            $value = $item;
            foreach (explode('.', $field) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$part];
            }
            if ($value !== null) {
                return $value;
            }
        }
        foreach (['employee', 'employment', 'staff', 'work_info', 'employee_info', 'job_info', 'profile'] as $group) {
            if (isset($item[$group]) && is_array($item[$group]) && array_key_exists($field, $item[$group])) {
                return $item[$group][$field];
            }
        }
        return null;
    }

    private function defaultCustomAttrMap($customKey) {
        if ($customKey === 'jobTitle') {
            return [
                'ids' => [],
                'names' => ['岗位', '职位', '职务', '职称']
            ];
        }
        if ($customKey === 'joinedAt') {
            return [
                'ids' => [],
                'names' => ['入职日期', '入职时间', '加入日期', '入职日']
            ];
        }
        return ['ids' => [], 'names' => []];
    }

    private function extractCustomAttrValue($item, $map) {
        if (!isset($item['custom_attrs']) || !is_array($item['custom_attrs']) || !is_array($map)) {
            return '';
        }
        $ids = isset($map['ids']) && is_array($map['ids']) ? array_map('strval', $map['ids']) : [];
        $names = isset($map['names']) && is_array($map['names']) ? array_map('strval', $map['names']) : [];
        foreach ($item['custom_attrs'] as $attr) {
            $attrId = (string)($attr['id'] ?? '');
            $i18nName = is_array($attr['i18n_name'] ?? null) ? $attr['i18n_name'] : [];
            $attrName = (string)($attr['name'] ?? ($i18nName['zh_cn'] ?? ($i18nName['zh-CN'] ?? '')));
            $idMatched = $attrId !== '' && in_array($attrId, $ids, true);
            $nameMatched = $attrName !== '' && in_array($attrName, $names, true);
            if (!$idMatched && !$nameMatched) {
                continue;
            }
            $text = $this->customValueText($attr['value'] ?? '');
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function customValueText($value) {
        if (is_scalar($value)) {
            return trim((string)$value);
        }
        if (!is_array($value)) {
            return '';
        }
        foreach (['text', 'value', 'name', 'date', 'datetime', 'option_value', 'pc_url'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') {
                return trim((string)$value[$key]);
            }
        }
        if (isset($value['option']) && is_array($value['option'])) {
            $text = $this->customValueText($value['option']);
            if ($text !== '') {
                return $text;
            }
        }
        foreach ($value as $item) {
            $text = $this->customValueText($item);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function parseDateTimeValue($value) {
        if (is_numeric($value)) {
            $timestamp = intval($value);
            if ($timestamp > 100000000000) {
                $timestamp = intval($timestamp / 1000);
            }
            return $timestamp > 0 ? $timestamp : 0;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return 0;
        }
        $timestamp = strtotime($text);
        return $timestamp !== false ? intval($timestamp) : 0;
    }

    private function limitText($value, $limit) {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (preg_match_all('/./u', $value, $matches) === false) {
            return substr($value, 0, $limit);
        }
        return implode('', array_slice($matches[0], 0, $limit));
    }

    private function isActiveStatus($status) {
        return $this->statusLifecycle($status) === 'active';
    }

    private function statusLifecycle($status) {
        if (!is_array($status) || count($status) === 0) {
            $value = strtolower(trim((string)$status));
            if (in_array($value, ['resigned', 'resign', 'deleted', 'delete', 'terminated', 'terminate', 'offboarded', 'offboarding', 'exited', 'exit'], true)) {
                return 'deleted';
            }
            if (in_array($value, ['inactive', 'disabled', 'disable', 'deactivated', 'frozen', 'unjoin'], true)) {
                return 'disabled';
            }
            return 'active';
        }
        if (
            (isset($status['is_resigned']) && $status['is_resigned'] == true) ||
            (isset($status['is_exited']) && $status['is_exited'] == true) ||
            (isset($status['is_deleted']) && $status['is_deleted'] == true)
        ) {
            return 'deleted';
        }
        if (
            (isset($status['is_activated']) && $status['is_activated'] !== true) ||
            (isset($status['is_frozen']) && $status['is_frozen'] == true) ||
            (isset($status['is_unjoin']) && $status['is_unjoin'] == true)
        ) {
            return 'disabled';
        }
        return 'active';
    }

    public function requestFeishu($url, $method = 'GET', $authorization = '', $body = null, $timeout = 60, $retries = 1) {
        $attempts = max(1, intval($retries));
        $lastResult = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $lastResult = $this->requestFeishuOnce($url, $method, $authorization, $body, $timeout);
            $statusCode = intval($lastResult['status_code'] ?? 0);
            $retryable = ($lastResult['error'] ?? '') !== '' || $statusCode === 0 || $statusCode === 429 || $statusCode >= 500;
            if (!$retryable || $attempt >= $attempts) {
                return $lastResult;
            }
            usleep(200000 * $attempt);
        }

        return $lastResult ?: [
            'status_code' => 0,
            'response' => null,
            'raw' => '',
            'error' => 'request failed before execution'
        ];
    }

    private function requestFeishuOnce($url, $method = 'GET', $authorization = '', $body = null, $timeout = 60) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);

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

    public function endpoint($key) {
        $configured = $this->_config['feishu']['appEndpoint'][$key] ?? '';
        if ($configured !== '') {
            return $configured;
        }
        $defaults = [
            'oauthAuthorize' => 'https://accounts.feishu.cn/open-apis/authen/v1/authorize',
            'getUserAccessTokenV3' => 'https://accounts.feishu.cn/oauth/v3/token',
            'getJsSdkTicket' => 'https://open.feishu.cn/open-apis/jssdk/ticket/get',
            'batchCreateAttendanceFlow' => 'https://open.feishu.cn/open-apis/attendance/v1/user_flows/batch_create'
        ];
        return $defaults[$key] ?? '';
    }

    private function getJsSdkTicket() {
        $now = time();
        if (!empty($this->keyContent['tenant_jssdk_ticket']) && !empty($this->keyContent['tenant_jssdk_ticket_expires_at']) && intval($this->keyContent['tenant_jssdk_ticket_expires_at']) > $now + 300) {
            return $this->keyContent['tenant_jssdk_ticket'];
        }

        $url = $this->endpoint('getJsSdkTicket');
        if ($url === '') {
            $this->lastError = '飞书 getJsSdkTicket endpoint 未在 config.php 中配置';
            return '';
        }

        $token = $this->getTenantAccessToken();
        if ($token === '') {
            $this->lastError = '无法获取 tenant_access_token';
            return '';
        }

        $data = $this->requestFeishu($url, 'POST', $token, new \stdClass(), 8, 2);
        if (($data['status_code'] ?? 0) == 200 && intval($data['response']['code'] ?? -1) === 0) {
            $ticketData = $data['response']['data'] ?? [];
            $ticket = $ticketData['ticket'] ?? ($ticketData['jsapi_ticket'] ?? ($data['response']['ticket'] ?? ''));
            if ($ticket !== '') {
                $expiresIn = intval($ticketData['expire_in'] ?? ($ticketData['expires_in'] ?? 7200));
                $this->keyContent['tenant_jssdk_ticket'] = $ticket;
                $this->keyContent['tenant_jssdk_ticket_expires_at'] = $now + $expiresIn;
                $this->saveKeyFile();
                return $ticket;
            }
        }

        $this->lastError = '获取飞书 JSAPI ticket 失败：' . json_encode($data, JSON_UNESCAPED_UNICODE);
        return '';
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
