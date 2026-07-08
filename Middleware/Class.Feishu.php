<?php

/*

飞书对接模块
Ver 1.0.0.0 20240705
Code by Jason

*/

namespace anim210System;

use anim210System;

class appLinkFeishu {
    private $_config;
    private $keyContent;

    public function __construct() {
        global $_config;
        $this->_config = $_config;
        $keyFile = file_get_contents($_config['feishu']['keyConfigFile']);
        $keyContent = json_decode($keyFile, true);
        // 检查是否解码成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            $keyContent = [];
        }

        $newTenantToken = $this->requestFeishu($_config['feishu']['appEndpoint']['getTenantToken'], 'POST', null, ['app_id' => $_config['feishu']['appId'], 'app_secret' => $_config['feishu']['appSecret']]);
        if ($newTenantToken['status_code'] != 200 || $newTenantToken['response']['msg'] != 'ok') {
            exit('Error: '.json_encode($newTenantToken));
        }
        // 修改tenant_access_token字段的值
        $keyContent['tenant_access_token'] = $newTenantToken['response']['tenant_access_token'];
        $this->keyContent = $keyContent;

        // 将修改后的数组编码为JSON字符串
        $newJsonContent = json_encode($keyContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // 检查是否编码成功
        if (json_last_error() !== JSON_ERROR_NONE) {
            exit('Error encoding JSON: ' . json_last_error_msg());
        }

        // 将新的JSON内容写回文件
        file_put_contents($_config['feishu']['keyConfigFile'], $newJsonContent);
    }

    public function getFeishuMemberList() {
        $allDepartments = [];
        $allMembers = [];
        $processedOpenIds = []; // 用于记录已经处理过的用户open_id

        // 初始调用递归函数
        $this->fetchDepartments($allDepartments);
        foreach ($allDepartments as $departmentId) {
            $this->fetchMembers($allMembers, $departmentId, $processedOpenIds);
        }
        return $allMembers;
    }

    public function verifyFeishuMemberStatus($open_id) {
        $url = $this->_config['feishu']['appEndpoint']['getMemberInfo'].$open_id.'?department_id_type=open_department_id&user_id_type=open_id';

        $data = $this->requestFeishu($url, 'GET', $this->keyContent['tenant_access_token'], null, 2);
        if (!isset($data['response']['data']['user']['status'])) {
            return true;
        }
        if ($data['response']['data']['user']['status']['is_activated'] !== true || $data['response']['data']['user']['status']['is_frozen'] == true || $data['response']['data']['user']['status']['is_resigned'] == true || $data['response']['data']['user']['status']['is_exited'] == true || $data['response']['data']['user']['status']['is_unjoin'] == true) {
            return false;
        }

        return true;
    }

    private function fetchDepartments(&$allDepartments, $pageToken = null) {
        $url = $this->_config['feishu']['appEndpoint']['getAllDepartments'];
        if ($pageToken) {
            $url .= '&page_token='.$pageToken;
        }
        $data = $this->requestFeishu($url, 'GET', $this->keyContent['tenant_access_token'], null);
        $departmentsData = $data['response'];

        // 检查响应数据结构
        if (isset($departmentsData['data']['items'])) {
            foreach ($departmentsData['data']['items'] as $item) {
                if (isset($item['open_department_id'])) {
                    $allDepartments[] = $item['open_department_id'];
                }
            }
        }

        // 检查是否有更多数据
        if (isset($departmentsData['data']['has_more']) && $departmentsData['data']['has_more'] === true) {
            if (isset($departmentsData['data']['page_token'])) {
                $nextPageToken = $departmentsData['data']['page_token'];
                $this->fetchDepartments($allDepartments, $nextPageToken);
            } else {
                exit('【500】Next page token is missing.');
            }
        }
    }

    private function fetchMembers(&$allMembers, $departmentId, &$processedOpenIds, $pageToken = null) {
        $url = $this->_config['feishu']['appEndpoint']['getDepartmentMemberInfo'];
        $url .= $departmentId;
        if ($pageToken) {
            $url .= '&page_token='.$pageToken;
        }
        $data = $this->requestFeishu($url, 'GET', $this->keyContent['tenant_access_token'], null);
        $membersData = $data['response'];

        // 检查响应数据结构
        if (isset($membersData['data']['items'])) {
            foreach ($membersData['data']['items'] as $item) {
                if (isset($item['open_id'], $item['name'], $item['employee_no'], $item['status'])) {
                    // 检查当前open_id是否已经处理过
                    if (in_array($item['open_id'], $processedOpenIds)) {
                        continue; // 跳过重复的open_id
                    }
                    // 记录当前open_id
                    $processedOpenIds[] = $item['open_id'];
                    if ($item['status']['is_activated'] !== true || $item['status']['is_frozen'] == true || $item['status']['is_resigned'] == true || $item['status']['is_exited'] == true || $item['status']['is_unjoin'] == true) {
                        $status = false;
                    } else {
                        $status = true;
                    }
                    // 搜索员工真实姓名字段
                    $realName  = '--';// 默认值
                    
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
                    $allMembers[] = [
                        'open_id' => $item['open_id'],
                        'name' => $item['name'],
                        'employee_no' => $item['employee_no'],
                        'real_name' => $realName,
                        'status' => $status
                    ];
                }
            }
        }

        // 检查是否有更多数据
        if (isset($membersData['data']['has_more']) && $membersData['data']['has_more'] === true) {
            if (isset($membersData['data']['page_token'])) {
                $nextPageToken = $membersData['data']['page_token'];
                $this->fetchMembers($allMembers, $departmentId, $processedOpenIds, $nextPageToken);
            } else {
                exit('【500】Next page token is missing.');
            }
        }
    }

    private function requestFeishu($url, $method = 'GET', $authorization = '', $body = [], $timeout = 60) {
        // 初始化Curl
        $ch = curl_init();
    
        // 设置URL
        curl_setopt($ch, CURLOPT_URL, $url);
    
        // 设置返回内容不直接输出
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // 设置请求超时时间
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    
        // 设置请求方法
        if (strtoupper($method) == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
    
            // 设置请求体
            $jsonBody = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    
            // 设置请求头，包含Content-Type: application/json
            $headers = ['Content-Type: application/json'];
        } else {
            // 默认为GET请求
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            $headers = [];
        }
    
        // 设置Authorization头
        if (!empty($authorization)) {
            $headers[] = 'Authorization: Bearer ' . $authorization;
        }
    
        // 设置所有头信息
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    
        // 执行请求并获取响应
        $response = curl_exec($ch);
    
        // 获取响应状态码
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
        // 关闭Curl
        curl_close($ch);
    
        return [
            'status_code' => $httpCode,
            'response' => json_decode($response, true)
        ];
    }
    
}